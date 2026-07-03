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
            'minor' => 'Sách hư hỏng nhẹ',
            'heavy' => 'Sách hư hỏng nặng',
            'lost'  => 'Mất sách',
        ];

        $nextId = (int)(DB::table('fines')->max('fine_id') ?? 0) + 1;

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

        DB::transaction(function () use ($fine, $method) {
            $nextPayId = (int)(DB::table('payments')->max('payment_id') ?? 0) + 1;

            DB::table('payments')->insert([
                'payment_id'   => $nextPayId,
                'fine_id'      => $fine->fine_id,
                'amount'       => $fine->amount,
                'method'       => $method,
                'payment_date' => now(),
            ]);

            DB::table('fines')
                ->where('fine_id', $fine->fine_id)
                ->update(['status' => 'paid']);
        });

        return ['fine_id' => $fineId, 'status' => 'paid', 'amount' => (float) $fine->amount];
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
            ->whereIn('config_key', ['damage_minor_percent', 'damage_heavy_percent', 'damage_lost_percent'])
            ->pluck('config_value', 'config_key');

        return match ($level) {
            'minor' => (int)($map['damage_minor_percent'] ?? 20),
            'heavy' => (int)($map['damage_heavy_percent'] ?? 50),
            'lost'  => (int)($map['damage_lost_percent']  ?? 100),
            default => 100,
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
}
