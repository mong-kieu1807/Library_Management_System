<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Module 7 (Phase 6A) — Backup & Restore. Chỉ xử lý backup (tạo/liệt kê/tải/xoá
 * file .sql) bằng mysqldump, không dùng package ngoài. Restore chưa làm ở phase này.
 */
class BackupService
{
    private string $backupDir;

    public function __construct()
    {
        // Yêu cầu literal "storage/app/backups" -> dùng thẳng storage_path(), không qua
        // disk 'local' (disk 'local' hiện trỏ vào storage/app/private, xem config/filesystems.php).
        $this->backupDir = storage_path('app/backups');
        File::ensureDirectoryExists($this->backupDir);
    }

    /**
     * Danh sách file backup (.sql), mới nhất trước.
     *
     * @return array<int, array{filename: string, size: int, created_at: string}>
     */
    public function list(): array
    {
        $paths = glob($this->backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];

        // Lọc trước các path đã biến mất giữa glob() và lúc đọc (TOCTOU hiếm gặp khi
        // có delete() chạy đồng thời) — tránh size/lastModified trả về false.
        $paths = array_filter($paths, 'is_file');

        $items = array_map(function (string $path) {
            $filename = basename($path);
            [$tsKey, $suffixKey] = $this->sortKeyFor($filename);

            return [
                'filename'   => $filename,
                'size'       => File::size($path),
                'created_at' => date('Y-m-d H:i:s', File::lastModified($path)),
                '_ts'        => $tsKey,
                '_suffix'    => $suffixKey,
            ];
        }, $paths);

        // Sắp xếp theo (phần ngày-giờ trong TÊN FILE, hậu tố số dạng int) — KHÔNG
        // dùng filemtime() (độ phân giải chỉ tới giây, không phân biệt được các file
        // tạo cùng giây có hậu tố _1/_2/... — đã verify filemtime() giống hệt nhau
        // cho các file tạo cách nhau <1s) và KHÔNG so sánh string nguyên tên file
        // (hậu tố "_10" sẽ đứng sai vị trí so với "_9" nếu so string thuần — đã tái
        // hiện lỗi này bằng test thật với 12 file trùng giây).
        usort($items, function ($a, $b) {
            $cmp = strcmp($b['_ts'], $a['_ts']);
            return $cmp !== 0 ? $cmp : ($b['_suffix'] <=> $a['_suffix']);
        });

        return array_map(fn ($item) => [
            'filename'   => $item['filename'],
            'size'       => $item['size'],
            'created_at' => $item['created_at'],
        ], $items);
    }

    /**
     * Trích timestamp + hậu tố số từ tên file để sắp xếp đúng thứ tự tạo, kể cả khi
     * nhiều file trùng giây (hậu tố _1, _2, ..., _10, _11...).
     *
     * @return array{0: string, 1: int}
     */
    private function sortKeyFor(string $filename): array
    {
        if (preg_match('/^library_backup_(\d{4}_\d{2}_\d{2}_\d{2}_\d{2}_\d{2})(?:_(\d+))?\.sql$/', $filename, $m)) {
            return [$m[1], isset($m[2]) ? (int) $m[2] : 0];
        }

        // Tên file không đúng định dạng chuẩn của service này (vd: đặt tay) -> xếp cuối.
        return ['', 0];
    }

    /**
     * Tạo file backup mới bằng mysqldump.
     *
     * @return array{filename: string, size: int, created_at: string}
     * @throws RuntimeException nếu mysqldump chạy thất bại
     */
    public function create(): array
    {
        // fopen(..., 'x') tạo file rỗng NGAY LẬP TỨC, atomic ở tầng hệ điều hành
        // (fail nếu đã tồn tại) -> "giữ chỗ" tên file trước khi chạy mysqldump (vốn
        // mất vài giây), tránh race condition: 2 request cùng giây trước đây sẽ tính
        // ra CÙNG tên file vì file thật chỉ được ghi sau khi mysqldump chạy xong
        // (đã tái hiện lỗi này bằng test trực tiếp trước khi sửa).
        [$filename, $path, $handle] = $this->claimFilename();

        try {
            $connection = config('database.connections.mysql');
            $mysqldump  = config('database.mysqldump_path');

            $result = Process::env(['MYSQL_PWD' => (string) $connection['password']])
                ->timeout(300)
                ->run([
                    $mysqldump,
                    '-h', (string) $connection['host'],
                    '-P', (string) $connection['port'],
                    '-u', (string) $connection['username'],
                    '--ssl', // TiDB Cloud từ chối kết nối không mã hoá ("insecure transport prohibited")
                    // KHÔNG dùng --single-transaction: mysqldump dùng SAVEPOINT nội bộ cho cờ này,
                    // TiDB không tương thích hoàn toàn -> lỗi "ROLLBACK TO SAVEPOINT sp does not exist"
                    // (đã verify bằng test thật). Chấp nhận đánh đổi vì DB nhỏ, không ghi đồng thời nặng.
                    '--skip-lock-tables',
                    '--no-tablespaces',
                    (string) $connection['database'],
                ]);

            if (!$result->successful()) {
                throw new RuntimeException('mysqldump lỗi: ' . trim($result->errorOutput()));
            }

            fwrite($handle, $result->output());
            fclose($handle);
            $handle = null;

            return [
                'filename'   => $filename,
                'size'       => File::size($path),
                'created_at' => date('Y-m-d H:i:s', File::lastModified($path)),
            ];
        } catch (\Throwable $e) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            // Dọn file rỗng đã "giữ chỗ" nếu thất bại, không để lại backup rỗng/hỏng.
            File::delete($path);

            throw $e instanceof RuntimeException ? $e : new RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Giữ chỗ atomic 1 tên file chưa tồn tại bằng fopen(..., 'x') — an toàn trước
     * race condition, khác với cách "check File::exists() rồi mới ghi" (có khoảng
     * hở giữa kiểm tra và ghi, 2 request cùng lúc có thể tính ra cùng tên).
     *
     * @return array{0: string, 1: string, 2: resource} [filename, full path, file handle đang mở]
     */
    private function claimFilename(): array
    {
        $base   = 'library_backup_' . now()->format('Y_m_d_H_i_s');
        $suffix = 0;

        while (true) {
            $filename = $suffix === 0 ? $base . '.sql' : $base . '_' . $suffix . '.sql';
            $path     = $this->backupDir . DIRECTORY_SEPARATOR . $filename;

            $handle = @fopen($path, 'x');
            if ($handle !== false) {
                // File backup chứa toàn bộ dữ liệu CSDL (kể cả password hash, PII) ->
                // giới hạn quyền chỉ chủ sở hữu đọc/ghi được, không world-readable.
                // (Trên Windows chmod() có tác dụng hạn chế, nhưng vô hại; giá trị thật
                // sự ở môi trường Linux/production).
                @chmod($path, 0600);
                return [$filename, $path, $handle];
            }

            $suffix++;
        }
    }

    /**
     * Trả về đường dẫn tuyệt đối AN TOÀN của 1 file backup, null nếu tên file không
     * hợp lệ (path traversal) hoặc file không tồn tại. Chặn theo 4 lớp:
     *   1. basename($filename) phải bằng chính $filename (không chứa / hoặc \)
     *   2. không được chứa '..'
     *   3. realpath() sau cùng phải nằm bên trong thư mục backups thật sự
     *   4. phải là FILE thường, không phải thư mục (chặn filename='.' trả về chính
     *      thư mục backups/ — đã tái hiện bug này bằng test thật: basename('.') === '.'
     *      và str_contains('.','..') === false nên lọt qua 2 lớp đầu, chỉ bị chặn nhờ
     *      route regex bên ngoài — không nên phụ thuộc hoàn toàn vào đó)
     */
    public function resolvePath(string $filename): ?string
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '..')) {
            return null;
        }

        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;

        if (!is_file($path)) {
            return null;
        }

        $real    = realpath($path);
        $realDir = realpath($this->backupDir);

        if ($real === false || $realDir === false || !str_starts_with($real, $realDir . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $real;
    }

    public function delete(string $filename): bool
    {
        $path = $this->resolvePath($filename);

        if ($path === null) {
            return false;
        }

        return File::delete($path);
    }
}
