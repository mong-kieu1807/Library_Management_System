<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class FineService
{
    // ─────────────────────────────────────────────────────────────
    // Feature 1 & 2 — Danh sách phí chưa thu (unpaid / partial)
    // ─────────────────────────────────────────────────────────────

    public function listFines(array $filters): array
    {
        $query = $this->baseFineQuery()
            ->whereIn('f.status', ['unpaid', 'partial']);

        $query = $this->applySearchFilter($query, $filters);

        if (!empty($filters['type'])) {
            $query->where('f.reason', 'LIKE', '%' . $this->reasonKeyword($filters['type']) . '%');
        }

        $total   = $query->count();
        $perPage = (int)($filters['per_page'] ?? 15);
        $page    = (int)($filters['page']     ?? 1);

        $rows = $query->orderByDesc('f.fine_id')
                      ->offset(($page - 1) * $perPage)
                      ->limit($perPage)
                      ->get();

        return [
            'data' => $rows->map(fn($r) => $this->formatFine($r))->values()->all(),
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Feature 2 — Tạo phí bồi thường hỏng / mất
    // ─────────────────────────────────────────────────────────────

    public function createDamageFine(array $data): array
    {
        // Lấy replacement_cost từ books qua book_copies
        $copy = DB::table('book_copies as bc')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bc.copy_id', $data['copy_id'])
            ->select('b.replacement_cost', 'b.title')
            ->first();

        if (!$copy) {
            throw new \RuntimeException('INVALID:copy not found');
        }

        $percent = $this->getDamagePercent($data['damage_level']);
        $amount  = (int) ceil(($copy->replacement_cost ?? 0) * $percent / 100);

        $reasons = [
            'minor'  => 'Sách hư hỏng nhẹ',
            'medium' => 'Sách hư hỏng vừa',
            'heavy'  => 'Sách hư hỏng nặng',
            'lost'   => 'Mất sách',
        ];

        // TiDB: fine_id không có AUTO_INCREMENT -> tự sinh id tuần tự trước khi insert
        // (cùng pattern đã dùng ở ReturnController::confirmReturn). lockForUpdate() để
        // tránh 2 request tạo phí đồng thời sinh trùng fine_id.
        $nextId = (int)(DB::table('fines')->lockForUpdate()->max('fine_id') ?? 0) + 1;

        DB::table('fines')->insert([
            'fine_id'    => $nextId,
            'user_id'    => (int) $data['user_id'],
            'borrow_id'  => isset($data['borrow_id']) ? (int) $data['borrow_id'] : null,
            'copy_id'    => (int) $data['copy_id'],
            'amount'     => $amount,
            'reason'     => $reasons[$data['damage_level']] ?? 'Phí bồi thường',
            'status'     => 'unpaid',
            'created_at' => now(),
        ]);

        return [
            'fine_id'       => $nextId,
            'amount'        => $amount,
            'damage_level'  => $data['damage_level'],
            'percent'       => $percent,
            'book_title'    => $copy->title,
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Feature 3 — Ghi nhận thanh toán tại quầy
    // ─────────────────────────────────────────────────────────────

    public function recordPayment(int $fineId, string $method): array
    {
        $fine = DB::table('fines')->where('fine_id', $fineId)->first();

        if (!$fine) {
            throw new \RuntimeException('INVALID:fine not found');
        }
        if ($fine->status === 'paid') {
            throw new \RuntimeException('INVALID:already paid');
        }

        // Chỉ áp dụng cho PHÍ TRỄ HẠN (reason chứa "trễ"/"quá hạn") — phí hỏng/mất sách
        // không phụ thuộc due_date/return_date nên không bị ràng buộc này (audit xác nhận
        // các fine đó luôn có borrow_id/copy_id hợp lệ, không cần thêm kiểm tra).
        $finalAmount = (float) $fine->amount;
        $finalReason = $fine->reason;

        if ($this->isLateReason($fine->reason)) {
            $borrow = DB::table('borrow_transactions as bt')
                ->leftJoin('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
                ->where('bt.borrow_id', $fine->borrow_id)
                ->where('bd.copy_id', $fine->copy_id)
                ->select('bt.due_date', 'bd.renewed_due_date', 'bd.return_date')
                ->first();

            $effectiveDue = $borrow ? ($borrow->renewed_due_date ?? $borrow->due_date) : null;
            if (!$effectiveDue) {
                throw new \RuntimeException('INVALID:no borrow data for late fine');
            }

            $actualDaysLate = OverdueCalculator::daysLate($effectiveDue, $borrow->return_date);

            // Chặn xác nhận thanh toán cho một khoản "trễ hạn" khi dữ liệu mượn/trả cho
            // thấy khoản đó chưa thực sự trễ (VD: effective_due_date còn ở tương lai) —
            // đúng lỗ hổng phát hiện qua audit dữ liệu thật (fine 45/49/50).
            if ($actualDaysLate <= 0) {
                throw new \RuntimeException('INVALID:not actually overdue');
            }

            // Sách CHƯA được trả (return_date NULL) => fine.amount trong DB có thể là
            // snapshot cũ, chưa từng được ReturnController chốt lại (confirmReturn() chỉ
            // chạy khi sách thực sự được trả — xem audit Mai Gia Bảo/fine 41: DB vẫn giữ
            // 5.000đ/"1 ngày" trong khi nợ thực tế đã lên tới hàng triệu đồng). PHẢI tính
            // lại số nợ THẬT bằng đúng công thức dùng chung (OverdueCalculator — giống hệt
            // "Đang mượn"/"Lịch sử phí" đang hiển thị cho độc giả) và chốt vào DB TRƯỚC khi
            // tạo payment, để payment.amount không lệch với số tiền độc giả nhìn thấy trên
            // màn hình. Nếu sách ĐÃ trả (return_date có giá trị), amount đã được
            // ReturnController chốt đúng tại thời điểm trả — không tính lại.
            if (!$borrow->return_date) {
                $finePerDay = (int) DB::table('system_settings')->where('config_key', 'fine_per_day')->value('config_value');
                $finalAmount = $actualDaysLate * $finePerDay;
                $finalReason = "Trả trễ {$actualDaysLate} ngày";
            }
        }

        // Phòng race condition/dữ liệu bất thường: fine chưa 'paid' nhưng đã có payment
        // (không nên xảy ra vì update status nằm chung transaction với insert payment bên
        // dưới, nhưng audit dữ liệu thật cho thấy không nên giả định điều này luôn đúng).
        if (DB::table('payments')->where('fine_id', $fineId)->exists()) {
            throw new \RuntimeException('INVALID:payment already exists');
        }

        DB::transaction(function () use ($fine, $method, $finalAmount, $finalReason) {
            // Chốt amount/reason THẬT trước khi ghi payment — cùng transaction, không giả
            // lập return_date, không đổi return_date/borrow status.
            if ($finalAmount !== (float) $fine->amount || $finalReason !== $fine->reason) {
                DB::table('fines')
                    ->where('fine_id', $fine->fine_id)
                    ->update(['amount' => $finalAmount, 'reason' => $finalReason]);
            }

            $nextPayId = (int)(DB::table('payments')->max('payment_id') ?? 0) + 1;

            DB::table('payments')->insert([
                'payment_id'   => $nextPayId,
                'fine_id'      => $fine->fine_id,
                'amount'       => $finalAmount,
                'method'       => $method,
                'payment_date' => now(),
            ]);

            DB::table('fines')
                ->where('fine_id', $fine->fine_id)
                ->update(['status' => 'paid']);
        });

        return ['fine_id' => $fineId, 'status' => 'paid', 'amount' => $finalAmount];
    }

    // ─────────────────────────────────────────────────────────────
    // Feature 4 — Lịch sử thu phí
    // ─────────────────────────────────────────────────────────────

    public function getHistory(array $filters): array
    {
        $query = $this->baseFineQuery()
            ->join('payments as p', 'p.fine_id', '=', 'f.fine_id')
            ->addSelect([
                DB::raw("DATE_FORMAT(p.payment_date, '%Y-%m-%d %H:%i') as paid_at"),
                'p.method as payment_method',
                'p.amount as paid_amount',
            ])
            ->where('f.status', 'paid');

        $query = $this->applySearchFilter($query, $filters);

        if (!empty($filters['date_from'])) {
            $query->whereDate('p.payment_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->whereDate('p.payment_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['method'])) {
            $query->where('p.method', $filters['method']);
        }

        $total   = $query->count();
        $perPage = (int)($filters['per_page'] ?? 15);
        $page    = (int)($filters['page']     ?? 1);

        $rows = $query->orderByDesc('p.payment_date')
                      ->offset(($page - 1) * $perPage)
                      ->limit($perPage)
                      ->get();

        return [
            'data' => $rows->map(function ($r) {
                $fine = $this->formatFine($r);
                $fine['paid_at']        = $r->paid_at        ?? null;
                $fine['payment_method'] = $r->payment_method ?? null;
                $fine['paid_amount']    = isset($r->paid_amount) ? (float) $r->paid_amount : null;
                return $fine;
            })->values()->all(),
            'meta' => [
                'total'        => $total,
                'per_page'     => $perPage,
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Feature 5 — Báo cáo doanh thu phí
    // ─────────────────────────────────────────────────────────────

    public function getRevenue(int $year, string $groupBy = 'month'): array
    {
        if ($groupBy === 'day') {
            $rows = DB::table('payments as p')
                ->join('fines as f', 'f.fine_id', '=', 'p.fine_id')
                ->whereYear('p.payment_date', $year)
                ->select([
                    DB::raw("DATE_FORMAT(p.payment_date, '%Y-%m-%d') as period"),
                    DB::raw('SUM(p.amount) as total'),
                    DB::raw('COUNT(*) as count'),
                ])
                ->groupBy(DB::raw("DATE_FORMAT(p.payment_date, '%Y-%m-%d')"))
                ->orderBy('period')
                ->get();
        } else {
            $rows = DB::table('payments as p')
                ->join('fines as f', 'f.fine_id', '=', 'p.fine_id')
                ->whereYear('p.payment_date', $year)
                ->select([
                    DB::raw('MONTH(p.payment_date) as period'),
                    DB::raw('SUM(p.amount) as total'),
                    DB::raw('COUNT(*) as count'),
                ])
                ->groupBy(DB::raw('MONTH(p.payment_date)'))
                ->orderBy('period')
                ->get();
        }

        // Breakdown by fine type (late / damage / lost)
        $breakdown = DB::table('payments as p')
            ->join('fines as f', 'f.fine_id', '=', 'p.fine_id')
            ->whereYear('p.payment_date', $year)
            ->select([
                DB::raw("CASE
                    WHEN f.reason LIKE '%trễ%' OR f.reason LIKE '%quá hạn%' THEN 'late'
                    WHEN f.reason LIKE '%mất%' THEN 'lost'
                    ELSE 'damage'
                END as type"),
                DB::raw('SUM(p.amount) as total'),
                DB::raw('COUNT(*) as count'),
            ])
            ->groupBy('type')
            ->get();

        $totalRevenue = $rows->sum('total');
        $totalCount   = $rows->sum('count');

        return [
            'year'          => $year,
            'group_by'      => $groupBy,
            'total_revenue' => (float) $totalRevenue,
            'total_count'   => (int)   $totalCount,
            'series'        => $rows->map(fn($r) => [
                'period' => $r->period,
                'total'  => (float) $r->total,
                'count'  => (int)   $r->count,
            ])->values()->all(),
            'breakdown' => $breakdown->map(fn($r) => [
                'type'  => $r->type,
                'total' => (float) $r->total,
                'count' => (int)   $r->count,
            ])->values()->all(),
        ];
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function baseFineQuery()
    {
        return DB::table('fines as f')
            ->join('users as u', 'u.user_id', '=', 'f.user_id')
            ->leftJoin('book_copies as bc', 'bc.copy_id', '=', 'f.copy_id')
            ->leftJoin('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('borrow_transactions as bt', 'bt.borrow_id', '=', 'f.borrow_id')
            ->select([
                'f.fine_id',
                'f.borrow_id',
                'f.copy_id',
                'u.user_id',
                'u.full_name as reader_name',
                'u.email as reader_email',
                'b.title as book_title',
                'bc.barcode as copy_barcode',
                'f.amount',
                'f.reason',
                'f.status',
                DB::raw("DATE_FORMAT(bt.due_date, '%Y-%m-%d') as due_date"),
                DB::raw("DATE_FORMAT(f.created_at, '%Y-%m-%d') as created_at"),
            ]);
    }

    private function applySearchFilter($query, array $filters)
    {
        if (!empty($filters['search'])) {
            $kw = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($kw) {
                $q->where('u.full_name', 'LIKE', $kw)
                  ->orWhere('b.title',   'LIKE', $kw)
                  ->orWhere('f.fine_id', 'LIKE', $kw);
            });
        }
        return $query;
    }

    private function formatFine(object $r): array
    {
        $type = 'late';
        if (str_contains($r->reason ?? '', 'mất')) $type = 'lost';
        elseif (str_contains($r->reason ?? '', 'hỏng') || str_contains($r->reason ?? '', 'hư')) $type = 'damage';

        return [
            'fine_id'      => $r->fine_id,
            'borrow_id'    => $r->borrow_id,
            'copy_id'      => $r->copy_id,
            'copy_barcode' => $r->copy_barcode ?? null,
            'user_id'      => $r->user_id,
            'reader_name'  => $r->reader_name,
            'reader_email' => $r->reader_email,
            'book_title'   => $r->book_title ?? '—',
            'amount'       => (float) $r->amount,
            'reason'       => $r->reason,
            'type'         => $type,
            'status'       => $r->status,
            'due_date'     => $r->due_date   ?? null,
            'created_at'   => $r->created_at ?? null,
        ];
    }

    private function getDamagePercent(string $level): int
    {
        $map = DB::table('system_settings')
            ->whereIn('config_key', [
                'damage_minor_percent',
                'damage_medium_percent',
                'damage_heavy_percent',
                'damage_lost_percent',
            ])
            ->pluck('config_value', 'config_key');

        return match ($level) {
            'minor'  => (int)($map['damage_minor_percent']  ?? 20),
            'medium' => (int)($map['damage_medium_percent'] ?? 35),
            'heavy'  => (int)($map['damage_heavy_percent']  ?? 50),
            'lost'   => (int)($map['damage_lost_percent']   ?? 100),
            default  => 100,
        };
    }

    private function reasonKeyword(string $type): string
    {
        return match ($type) {
            'late'   => 'hạn',
            'damage' => 'hỏng',
            'lost'   => 'mất',
            default  => '',
        };
    }

    // Dùng riêng cho validate ở recordPayment() — whitelist rõ ràng, không mặc định 'late'
    // cho reason không nhận diện được (khác kiểu blacklist ở formatFine()/type ở trên, vốn
    // là code cũ đang phục vụ danh sách/lọc phí, không đổi để tránh ảnh hưởng nơi khác đang dùng).
    private function isLateReason(?string $reason): bool
    {
        $reason = $reason ?? '';
        return str_contains($reason, 'trễ') || str_contains($reason, 'quá hạn');
    }

}
