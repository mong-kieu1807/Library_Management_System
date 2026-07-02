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

        $items = array_map(function (string $path) {
            return [
                'filename'   => basename($path),
                'size'       => File::size($path),
                'created_at' => date('Y-m-d H:i:s', File::lastModified($path)),
            ];
        }, $paths);

        usort($items, fn ($a, $b) => strcmp($b['filename'], $a['filename']));

        return $items;
    }

    /**
     * Tạo file backup mới bằng mysqldump.
     *
     * @return array{filename: string, size: int, created_at: string}
     * @throws RuntimeException nếu mysqldump chạy thất bại
     */
    public function create(): array
    {
        [$filename, $path] = $this->nextAvailableFilename();

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

        File::put($path, $result->output());

        return [
            'filename'   => $filename,
            'size'       => File::size($path),
            'created_at' => date('Y-m-d H:i:s', File::lastModified($path)),
        ];
    }

    /**
     * "Không ghi đè file cũ" — tên file có độ chính xác tới giây nên trùng là cực hiếm,
     * nhưng vẫn phòng thủ: nếu đã tồn tại thì thêm hậu tố tăng dần.
     *
     * @return array{0: string, 1: string} [filename, full path]
     */
    private function nextAvailableFilename(): array
    {
        $base     = 'library_backup_' . now()->format('Y_m_d_H_i_s');
        $filename = $base . '.sql';
        $path     = $this->backupDir . DIRECTORY_SEPARATOR . $filename;

        $suffix = 1;
        while (File::exists($path)) {
            $filename = $base . '_' . $suffix . '.sql';
            $path     = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
            $suffix++;
        }

        return [$filename, $path];
    }

    /**
     * Trả về đường dẫn tuyệt đối AN TOÀN của 1 file backup, null nếu tên file không
     * hợp lệ (path traversal) hoặc file không tồn tại. Chặn theo 3 lớp:
     *   1. basename($filename) phải bằng chính $filename (không chứa / hoặc \)
     *   2. không được chứa '..'
     *   3. realpath() sau cùng phải nằm bên trong thư mục backups thật sự
     */
    public function resolvePath(string $filename): ?string
    {
        if ($filename === '' || basename($filename) !== $filename || str_contains($filename, '..')) {
            return null;
        }

        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;

        if (!File::exists($path)) {
            return null;
        }

        $real    = realpath($path);
        $realDir = realpath($this->backupDir);

        if ($real === false || $realDir === false || !str_starts_with($real, $realDir)) {
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
