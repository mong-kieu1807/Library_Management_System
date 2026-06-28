<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ReportService
{
    /**
     * Phase 1 — Báo cáo giao dịch mượn/trả.
     *
     * Summary: tổng mượn, tổng trả trong khoảng ngày.
     *          active_borrows và overdue là real-time (không filter ngày).
     * Chart:   số lượt mượn và trả theo từng period (day/week/month).
     */
    public function getTransactionReport(string $fromDate, string $toDate, string $groupBy): array
    {
        // ── 1. Summary ────────────────────────────────────────────────────────

        $totalBorrows = DB::table('borrow_transactions')
            ->whereBetween('borrow_date', [$fromDate, $toDate])
            ->count();

        $totalReturns = DB::table('borrow_details')
            ->whereNotNull('return_date')
            ->whereBetween('return_date', [$fromDate, $toDate])
            ->count();

        // Giao dịch đang mượn theo status của transaction (source of truth)
        $activeBorrows = DB::table('borrow_transactions')
            ->where('status', 'borrowing')
            ->count();

        // Quá hạn: borrowing + due_date đã qua hôm nay
        $overdue = DB::table('borrow_transactions')
            ->where('status', 'borrowing')
            ->whereDate('due_date', '<', Carbon::today()->toDateString())
            ->count();

        // ── 2. Chart: aggregate theo period ──────────────────────────────────

        [$borrowFmt, $returnFmt] = match ($groupBy) {
            'day'   => ["DATE_FORMAT(borrow_date, '%Y-%m-%d')", "DATE_FORMAT(return_date, '%Y-%m-%d')"],
            'week'  => ["DATE_FORMAT(borrow_date, '%x-%v')", "DATE_FORMAT(return_date, '%x-%v')"],
            default => ["DATE_FORMAT(borrow_date, '%Y-%m')", "DATE_FORMAT(return_date, '%Y-%m')"],
        };

        $borrowsByPeriod = DB::table('borrow_transactions')
            ->selectRaw("{$borrowFmt} AS period, COUNT(*) AS cnt")
            ->whereBetween('borrow_date', [$fromDate, $toDate])
            ->groupBy('period')
            ->orderBy('period')
            ->pluck('cnt', 'period');

        $returnsByPeriod = DB::table('borrow_details')
            ->selectRaw("{$returnFmt} AS period, COUNT(*) AS cnt")
            ->whereNotNull('return_date')
            ->whereBetween('return_date', [$fromDate, $toDate])
            ->groupBy('period')
            ->orderBy('period')
            ->pluck('cnt', 'period');

        // ── 3. Sinh đủ tất cả period trong khoảng, điền 0 nếu không có data ─

        $chart = [];

        if ($groupBy === 'day') {
            $cursor = Carbon::parse($fromDate)->startOfDay();
            $end    = Carbon::parse($toDate)->startOfDay();
            while ($cursor->lte($end)) {
                $key     = $cursor->format('Y-m-d');
                $chart[] = [
                    'period'  => $key,
                    'label'   => $cursor->format('d/m'),
                    'borrows' => (int) ($borrowsByPeriod[$key] ?? 0),
                    'returns' => (int) ($returnsByPeriod[$key] ?? 0),
                ];
                $cursor->addDay();
            }
        } elseif ($groupBy === 'week') {
            // Dùng ISO week (%x-%v trong MySQL) để khớp với Carbon isoWeekYear/isoWeek
            $cursor  = Carbon::parse($fromDate)->startOfWeek(Carbon::MONDAY);
            $endWeek = Carbon::parse($toDate)->startOfWeek(Carbon::MONDAY);
            while ($cursor->lte($endWeek)) {
                $isoYear = $cursor->isoWeekYear();
                $isoWeek = $cursor->isoWeek();
                $key     = $isoYear . '-' . str_pad((string) $isoWeek, 2, '0', STR_PAD_LEFT);
                $chart[] = [
                    'period'  => $key,
                    'label'   => 'T' . $isoWeek . '/' . $isoYear,
                    'borrows' => (int) ($borrowsByPeriod[$key] ?? 0),
                    'returns' => (int) ($returnsByPeriod[$key] ?? 0),
                ];
                $cursor->addWeek();
            }
        } else {
            // month
            $cursor = Carbon::parse($fromDate)->startOfMonth();
            $end    = Carbon::parse($toDate);
            while ($cursor->lte($end)) {
                $key     = $cursor->format('Y-m');
                $chart[] = [
                    'period'  => $key,
                    'label'   => 'T' . $cursor->month . '/' . $cursor->year,
                    'borrows' => (int) ($borrowsByPeriod[$key] ?? 0),
                    'returns' => (int) ($returnsByPeriod[$key] ?? 0),
                ];
                $cursor->addMonth();
            }
        }

        return [
            'summary' => [
                'total_borrows'  => $totalBorrows,
                'total_returns'  => $totalReturns,
                'active_borrows' => $activeBorrows,
                'overdue'        => $overdue,
            ],
            'chart' => $chart,
        ];
    }

    /**
     * Phase 2 — Top sách được mượn nhiều nhất.
     *
     * Dùng 2 query (không N+1):
     *   Query 1: Đếm lượt mượn theo book_id — GROUP BY cho COUNT(*) chính xác.
     *   Query 2: Lấy categories cho tập book_id vừa lấy — dùng WHERE IN, 1 query duy nhất.
     *
     * Lý do KHÔNG JOIN categories trong Query 1:
     *   Sách có N thể loại → sau JOIN sẽ có N hàng cho mỗi lượt mượn
     *   → COUNT(*) bị nhân N lần → sai kết quả.
     */
    public function getTopBooks(string $fromDate, string $toDate, int $limit): array
    {
        // ── Query 1: top books + borrow count ────────────────────────────────
        //
        // Chuỗi JOIN:
        //   borrow_details   — mỗi hàng = 1 bản sao được mượn (copy_id)
        //       ↓ borrow_id
        //   borrow_transactions — có borrow_date để filter ngày
        //       ↓ copy_id
        //   book_copies      — bản sao thuộc sách nào (book_id)
        //       ↓ book_id
        //   books            — thông tin sách (title, cover_image, author_id)
        //       ↓ author_id
        //   authors          — tên tác giả chính (LEFT JOIN — sách có thể không có tác giả)
        //
        // GROUP BY b.book_id → mỗi nhóm là 1 cuốn sách
        // COUNT(*) → đếm số hàng trong borrow_details = số lượt bản sao đó được mượn
        // ORDER BY borrow_count DESC → sách nhiều lượt nhất lên trước
        // LIMIT → chỉ lấy top N

        $rows = DB::table('borrow_details as bd')
            ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
            ->join('book_copies as bc',         'bc.copy_id',   '=', 'bd.copy_id')
            ->join('books as b',                'b.book_id',    '=', 'bc.book_id')
            ->leftJoin('authors as a',          'a.author_id',  '=', 'b.author_id')
            ->select([
                'b.book_id',
                'b.title',
                'b.cover_image',
                DB::raw("COALESCE(a.author_name, '') AS author_name"),
                DB::raw('COUNT(*) AS borrow_count'),
            ])
            ->whereBetween('bt.borrow_date', [$fromDate, $toDate])
            ->groupBy('b.book_id', 'b.title', 'b.cover_image', 'a.author_name')
            ->orderByDesc('borrow_count')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // ── Query 2: categories cho các book vừa lấy ─────────────────────────
        //
        // Dùng WHERE IN (book_ids) thay vì query từng book → tránh N+1.
        // groupBy('book_id') trong PHP: gom các category của cùng 1 book lại
        // pluck('category_name')->join(', '): ghép thành chuỗi "Kỹ năng sống, Tâm lý học"

        $bookIds     = $rows->pluck('book_id')->all();
        $categoryMap = DB::table('book_categories as bcat')
            ->join('categories as c', 'c.category_id', '=', 'bcat.category_id')
            ->whereIn('bcat.book_id', $bookIds)
            ->select('bcat.book_id', 'c.category_name')
            ->orderBy('c.category_name')
            ->get()
            ->groupBy('book_id')
            ->map(fn($cats) => $cats->pluck('category_name')->join(', '));

        // ── Map kết quả, thêm rank ────────────────────────────────────────────
        return $rows->values()->map(function ($row, $index) use ($categoryMap) {
            return [
                'rank'           => $index + 1,
                'book_id'        => $row->book_id,
                'title'          => $row->title,
                'cover_image'    => $row->cover_image,
                'author_name'    => $row->author_name,
                'category_names' => $categoryMap[$row->book_id] ?? null,
                'borrow_count'   => (int) $row->borrow_count,
            ];
        })->all();
    }

    /**
     * Phase 3A — Top độc giả mượn nhiều nhất.
     *
     * Chỉ dùng 1 query (không N+1):
     *   JOIN: borrow_transactions → users → roles
     *   WHERE: role_name = 'reader' + borrow_date BETWEEN
     *   GROUP BY: user_id
     *   COUNT: borrow_id (số phiếu mượn = số lần ra thư viện)
     *
     * Tại sao COUNT borrow_id thay vì JOIN borrow_details:
     *   Metric "top readers" = người mượn nhiều LẦN nhất (số phiếu).
     *   Một phiếu có thể gồm nhiều bản sao — đếm bản sao sẽ thiên vị người mượn
     *   nhiều sách 1 lần thay vì người lui tới thư viện thường xuyên.
     *
     * Tại sao không cần query thứ 2 (khác Phase 2):
     *   users không có quan hệ 1-N với bảng nào khác trong SELECT này.
     *   full_name, email, avatar_url đều là cột của users → lấy trong cùng JOIN,
     *   không có nguy cơ cartesian product.
     */
    public function getTopReaders(string $fromDate, string $toDate, int $limit): array
    {
        // ── Query duy nhất: đếm phiếu mượn theo từng độc giả ─────────────────
        //
        // Bắt đầu từ borrow_transactions (có borrow_date để filter):
        //   borrow_transactions (bt) — mỗi hàng = 1 phiếu mượn
        //       ↓ bt.user_id = u.user_id
        //   users (u) — thông tin độc giả (full_name, email, avatar_url)
        //       ↓ u.role_id = r.role_id
        //   roles (r) — filter chỉ lấy role_name = 'reader'
        //               (loại admin, librarian ra khỏi bảng xếp hạng)
        //
        // GROUP BY u.user_id → mỗi nhóm là 1 người dùng
        // COUNT(bt.borrow_id) → đếm số phiếu mượn của người đó trong khoảng ngày
        // ORDER BY borrow_count DESC → người mượn nhiều nhất lên đầu
        // LIMIT $limit → chỉ lấy top N

        $rows = DB::table('borrow_transactions as bt')
            ->join('users as u', 'u.user_id', '=', 'bt.user_id')
            ->join('roles as r', 'r.role_id', '=', 'u.role_id')
            ->where('r.role_name', 'reader')
            ->whereBetween('bt.borrow_date', [$fromDate, $toDate])
            ->select([
                'u.user_id',
                'u.full_name',
                'u.email',
                'u.avatar_url',
                DB::raw('COUNT(bt.borrow_id) AS borrow_count'),
            ])
            ->groupBy('u.user_id', 'u.full_name', 'u.email', 'u.avatar_url')
            ->orderByDesc('borrow_count')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // ── Map kết quả, thêm rank ────────────────────────────────────────────
        return $rows->values()->map(function ($row, $index) {
            return [
                'rank'         => $index + 1,
                'user_id'      => $row->user_id,
                'full_name'    => $row->full_name,
                'email'        => $row->email,
                'avatar_url'   => $row->avatar_url,
                'borrow_count' => (int) $row->borrow_count,
            ];
        })->all();
    }

    /**
     * Phase 3B — Xu hướng đăng ký độc giả mới theo tháng.
     *
     * Chỉ dùng 1 query:
     *   JOIN: users → roles WHERE role_name = 'reader'
     *   WHERE: created_at BETWEEN
     *   GROUP BY: DATE_FORMAT(created_at, '%Y-%m')
     *   COUNT(*): số độc giả mới đăng ký trong tháng đó
     *
     * Sau đó PHP sinh đủ tất cả tháng trong khoảng, điền 0 cho tháng không có đăng ký.
     * (Giống cách Phase 1 sinh đủ period — đảm bảo chart không bị hụt điểm.)
     *
     * Tại sao luôn group theo tháng (không có group_by param):
     *   Đăng ký thành viên là metric chậm — theo ngày quá nhiễu, theo tuần không
     *   có chuẩn thông dụng. Tháng là granularity tiêu chuẩn cho membership report.
     */
    public function getReaderRegistrationTrend(string $fromDate, string $toDate): array
    {
        // ── Query: đếm đăng ký mới theo tháng ────────────────────────────────
        //
        // Bắt đầu từ users (có created_at để group và filter):
        //   users (u) — created_at = ngày tạo tài khoản
        //       ↓ u.role_id = r.role_id
        //   roles (r) — filter role_name = 'reader' (không đếm admin/librarian)
        //
        // DATE_FORMAT(u.created_at, '%Y-%m') → chuỗi 'YYYY-MM' dùng làm period key
        // COUNT(*) → số user tạo trong tháng đó
        // ORDER BY period → sắp xếp tăng dần theo thời gian cho chart

        // Dùng whereDate() thay vì whereBetween() vì created_at là DATETIME.
        // whereBetween với date string '2026-06-29' sẽ bị cast thành '2026-06-29 00:00:00',
        // bỏ sót toàn bộ user đăng ký trong ngày đó sau 00:00:00.
        // whereDate() áp dụng DATE() lên cột → so sánh ngày thuần túy, không lệ thuộc giờ.
        $rawData = DB::table('users as u')
            ->join('roles as r', 'r.role_id', '=', 'u.role_id')
            ->where('r.role_name', 'reader')
            ->whereDate('u.created_at', '>=', $fromDate)
            ->whereDate('u.created_at', '<=', $toDate)
            ->selectRaw("DATE_FORMAT(u.created_at, '%Y-%m') AS period, COUNT(*) AS cnt")
            ->groupBy('period')
            ->orderBy('period')
            ->pluck('cnt', 'period'); // ['2025-07' => 12, '2025-09' => 8, ...]

        // ── Sinh đủ tất cả tháng, điền 0 cho tháng trống ────────────────────
        //
        // Nếu không có đăng ký nào trong tháng 2025-08, $rawData không có key đó.
        // Chart sẽ bị hụt cột → gây hiểu nhầm.
        // Giải pháp: duyệt từng tháng trong range, dùng ?? 0 để điền số 0.

        $trend  = [];
        $cursor = Carbon::parse($fromDate)->startOfMonth();
        $end    = Carbon::parse($toDate);

        while ($cursor->lte($end)) {
            $key     = $cursor->format('Y-m');
            $trend[] = [
                'period' => $key,
                'label'  => 'T' . $cursor->month . '/' . $cursor->year,
                'count'  => (int) ($rawData[$key] ?? 0),
            ];
            $cursor->addMonth();
        }

        return $trend;
    }
}
