<?php

namespace App\Console\Commands;

use App\Services\OverdueCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Đồng bộ MỘT LẦN, có kiểm soát, cột fines.amount/reason cho các khoản phí trễ hạn
 * CHƯA THANH TOÁN — vốn chỉ là snapshot tại thời điểm tạo/lần đồng bộ gần nhất, không tự
 * tăng theo ngày hiện tại (API GET /v1/me/fines tính runtime để hiển thị, không UPDATE DB).
 *
 * Lệnh này là "thời điểm nghiệp vụ" đồng bộ dữ liệu — KHÔNG chạy tự động, KHÔNG chạy theo
 * lịch, chỉ chạy khi admin chủ động gọi.
 *
 * Mặc định LUÔN là dry-run (chỉ in báo cáo, không UPDATE gì). Phải truyền --apply mới ghi
 * dữ liệu, và khi đó bọc trong 1 DB transaction duy nhất.
 */
class SyncUnpaidLateFines extends Command
{
    protected $signature = 'fines:sync-unpaid-late {--apply : Thực sự UPDATE database (mặc định chỉ dry-run)}';

    protected $description = 'Đồng bộ amount/reason của các fine trễ hạn chưa thanh toán theo đúng số ngày quá hạn thực tế (OverdueCalculator) — mặc định dry-run, cần --apply để ghi DB';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $finePerDay = (int) DB::table('system_settings')->where('config_key', 'fine_per_day')->value('config_value');
        if ($finePerDay <= 0) {
            $this->error('system_settings.fine_per_day không hợp lệ (<= 0) — dừng lại, không xử lý gì.');
            return self::FAILURE;
        }

        $rows = DB::table('fines as f')
            ->where('f.status', 'unpaid')
            ->leftJoin('borrow_transactions as bt', 'bt.borrow_id', '=', 'f.borrow_id')
            ->leftJoin('borrow_details as bd', function ($j) {
                $j->on('bd.borrow_id', '=', 'f.borrow_id')->on('bd.copy_id', '=', 'f.copy_id');
            })
            ->leftJoin('payments as p', 'p.fine_id', '=', 'f.fine_id')
            ->select([
                'f.fine_id', 'f.user_id', 'f.borrow_id', 'f.copy_id',
                'f.amount as db_amount', 'f.reason as db_reason',
                'bt.due_date', 'bd.renewed_due_date', 'bd.return_date',
                'p.payment_id',
            ])
            ->orderBy('f.fine_id')
            ->get();

        $safe = [];
        $skip = [];

        foreach ($rows as $r) {
            $isLate = str_contains($r->db_reason ?? '', 'trễ') || str_contains($r->db_reason ?? '', 'quá hạn');

            if (!$isLate) {
                $skip[] = [$r, 'không xác định chắc chắn là phí trễ hạn (reason không chứa "trễ"/"quá hạn")'];
                continue;
            }
            if (!$r->due_date) {
                $skip[] = [$r, 'không tìm thấy borrow_transaction/borrow_details hợp lệ cho borrow_id/copy_id này'];
                continue;
            }
            if ($r->payment_id) {
                $skip[] = [$r, 'status=unpaid nhưng đã tồn tại payment — dữ liệu mâu thuẫn, không tự xử lý'];
                continue;
            }

            $effectiveDue = $r->renewed_due_date ?? $r->due_date;
            $actualDaysLate = OverdueCalculator::daysLate($effectiveDue, $r->return_date);

            if ($actualDaysLate <= 0) {
                $skip[] = [$r, 'effective_due_date chưa qua (hoặc return_date <= due_date) — không thực sự trễ, không backfill'];
                continue;
            }

            $expectedAmount = $actualDaysLate * $finePerDay;
            $expectedReason = "Trả trễ {$actualDaysLate} ngày";

            $safe[] = [
                'row'             => $r,
                'effective_due'   => $effectiveDue,
                'actual_days'     => $actualDaysLate,
                'expected_amount' => $expectedAmount,
                'expected_reason' => $expectedReason,
                'needs_update'    => (float) $r->db_amount !== (float) $expectedAmount || $r->db_reason !== $expectedReason,
            ];
        }

        $this->info('=== AUDIT: fine trễ hạn UNPAID ===');
        $this->table(
            ['fine_id', 'user_id', 'borrow_id', 'copy_id', 'DB amount', 'DB reason', 'return_date', 'actual_days_late', 'expected_amount', 'cần cập nhật?'],
            collect($safe)->map(fn ($s) => [
                $s['row']->fine_id, $s['row']->user_id, $s['row']->borrow_id, $s['row']->copy_id,
                number_format((float) $s['row']->db_amount), $s['row']->db_reason,
                $s['row']->return_date ?? 'NULL (chưa trả)',
                $s['actual_days'], number_format($s['expected_amount']),
                $s['needs_update'] ? 'CÓ' : 'không (đã khớp)',
            ])
        );

        if (!empty($skip)) {
            $this->warn('=== DO NOT BACKFILL (bỏ qua, không đủ căn cứ) ===');
            $this->table(
                ['fine_id', 'user_id', 'borrow_id', 'lý do bỏ qua'],
                collect($skip)->map(fn ($s) => [$s[0]->fine_id, $s[0]->user_id, $s[0]->borrow_id, $s[1]])
            );
        }

        $toUpdate = collect($safe)->filter(fn ($s) => $s['needs_update'])->values();

        if ($toUpdate->isEmpty()) {
            $this->info('Không có fine nào cần cập nhật — dữ liệu đã khớp hoặc không có fine SAFE TO BACKFILL nào.');
            return self::SUCCESS;
        }

        if (!$apply) {
            $this->newLine();
            $this->info("DRY-RUN: {$toUpdate->count()} fine sẽ được cập nhật nếu chạy lại với --apply. Chưa có gì được ghi vào database.");
            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn("--apply: chuẩn bị UPDATE {$toUpdate->count()} fine trong 1 DB transaction...");

        DB::transaction(function () use ($toUpdate) {
            foreach ($toUpdate as $s) {
                DB::table('fines')
                    ->where('fine_id', $s['row']->fine_id)
                    ->where('status', 'unpaid') // re-check ngay trước UPDATE, phòng race condition
                    ->update([
                        'amount' => $s['expected_amount'],
                        'reason' => $s['expected_reason'],
                    ]);
            }
        });

        $this->info("Đã cập nhật {$toUpdate->count()} fine.");
        return self::SUCCESS;
    }
}
