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
     * Phase 4 — Doanh thu tiền phạt theo tháng.
     *
     * Nguồn dữ liệu: bảng payments (JOIN fines để có context nguyên nhân).
     *
     * Tại sao dùng payments.payment_date thay vì fines.created_at:
     *   "Doanh thu" = tiền thực tế đã thu vào tay thư viện.
     *   Phiếu phạt phát sinh tháng 1 (fines.created_at = T1) có thể được
     *   nộp tháng 3 (payments.payment_date = T3) → doanh thu thuộc về T3.
     *   Dùng created_at sẽ ghi nhận sai kỳ kế toán.
     *
     * JOIN: payments (p) → fines (f) qua fine_id.
     *   Inner JOIN đủ vì mọi payment đều có fine tương ứng (FK constraint).
     *
     * GROUP BY DATE_FORMAT(payment_date, '%Y-%m') → tổng hợp theo tháng.
     * SUM(p.amount) → tổng tiền thực thu trong tháng.
     * COUNT(*) → số phiếu phạt đã thu trong tháng.
     *
     * PHP fill missing months → đảm bảo chart không hụt cột.
     * Không N+1: 1 query duy nhất.
     */
    public function getFineRevenue(string $fromDate, string $toDate): array
    {
        // ── Query: tổng doanh thu theo tháng ────────────────────────────────
        //
        // Bắt đầu từ payments (có payment_date để filter và group):
        //   payments (p) — mỗi hàng = 1 khoản thanh toán thực tế
        //       ↓ p.fine_id = f.fine_id
        //   fines (f) — thông tin phiếu phạt (amount, reason — để tham chiếu nếu cần)
        //
        // whereDate() thay vì whereBetween() vì payment_date là DATETIME.
        // DATE() strip giờ → so sánh ngày thuần túy, tránh bỏ sót giao dịch cuối ngày.

        // 1 query duy nhất — lấy cả revenue và fine_count cùng lúc
        // keyBy('period') → O(1) lookup trong vòng lặp sinh tháng bên dưới
        $rawData = DB::table('payments as p')
            ->join('fines as f', 'f.fine_id', '=', 'p.fine_id')
            ->whereDate('p.payment_date', '>=', $fromDate)
            ->whereDate('p.payment_date', '<=', $toDate)
            ->selectRaw("DATE_FORMAT(p.payment_date, '%Y-%m') AS period, SUM(p.amount) AS revenue, COUNT(*) AS fine_count")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        // ── Sinh đủ tất cả tháng, điền 0 cho tháng không có doanh thu ────────
        $trend  = [];
        $cursor = Carbon::parse($fromDate)->startOfMonth();
        $end    = Carbon::parse($toDate);

        while ($cursor->lte($end)) {
            $key     = $cursor->format('Y-m');
            $trend[] = [
                'period'     => $key,
                'label'      => 'T' . $cursor->month . '/' . $cursor->year,
                'revenue'    => isset($rawData[$key]) ? (float) $rawData[$key]->revenue    : 0.0,
                'fine_count' => isset($rawData[$key]) ? (int)   $rawData[$key]->fine_count : 0,
            ];
            $cursor->addMonth();
        }

        return $trend;
    }

    /**
     * Phase 4 — Thống kê nguyên nhân phát sinh tiền phạt (all-time).
     *
     * Nguồn dữ liệu: bảng fines (không cần JOIN — reason, amount là cột của fines).
     *
     * Chiến lược phân loại:
     *   fines.reason là free-text → dùng CASE LIKE để nhóm vào 4 danh mục cố định.
     *   Pattern mapping:
     *     'Trả trễ%' | 'Trả sách quá hạn%' → 'Trả sách trễ hạn'  (ReturnController tạo)
     *     '%mất%'                           → 'Mất sách'          (manual nhập)
     *     '%rách%' | '%hư hỏng%' | '%xước%' → 'Hư hỏng sách'      (manual nhập)
     *     else                              → 'Khác'
     *
     * GROUP BY alias (MySQL / TiDB hỗ trợ) → 1 query đủ tất cả.
     * keyBy('category') → O(1) lookup khi build output array cố định 4 phần tử.
     * Luôn trả đủ 4 danh mục (kể cả khi count=0) → frontend không cần handle array thiếu.
     * Không N+1: 1 query duy nhất.
     */
    public function getFineReasons(): array
    {
        $rows = DB::table('fines')
            ->selectRaw("
                CASE
                    WHEN reason LIKE 'Trả trễ%' OR reason LIKE 'Trả sách quá hạn%'
                        THEN 'Trả sách trễ hạn'
                    WHEN reason LIKE '%mất%'
                        THEN 'Mất sách'
                    WHEN reason LIKE '%rách%' OR reason LIKE '%hư hỏng%' OR reason LIKE '%xước%' OR reason LIKE '%hỏng%'
                        THEN 'Hư hỏng sách'
                    ELSE 'Khác'
                END AS category,
                COUNT(*) AS fine_count,
                SUM(amount) AS total_amount
            ")
            ->groupBy('category')
            ->get()
            ->keyBy('category');

        // ── Trả đủ 4 danh mục theo thứ tự hiển thị cố định ──────────────────
        $categories = ['Trả sách trễ hạn', 'Mất sách', 'Hư hỏng sách', 'Khác'];

        return array_map(fn ($cat) => [
            'category'     => $cat,
            'fine_count'   => (int)   ($rows[$cat]->fine_count   ?? 0),
            'total_amount' => (float) ($rows[$cat]->total_amount ?? 0),
        ], $categories);
    }

    /**
     * Phase 4 — Danh sách bản sao sách đang quá hạn.
     *
     * Điều kiện quá hạn: borrow_transactions.status = 'borrowing'
     *                  AND borrow_transactions.due_date < CURDATE()
     *                  AND borrow_details.return_date IS NULL (bản sao chưa được trả)
     *
     * Chuỗi JOIN:
     *   borrow_transactions (bt) — 1 phiếu mượn, chứa due_date và status
     *       ↓ bt.user_id = u.user_id
     *   users (u) — thông tin độc giả (full_name, email)
     *       ↓ bt.borrow_id = bd.borrow_id
     *   borrow_details (bd) — mỗi hàng = 1 bản sao trong phiếu mượn
     *       ↓ bd.copy_id = bc.copy_id
     *   book_copies (bc) — bản sao thuộc sách nào
     *       ↓ bc.book_id = b.book_id
     *   books (b) — tên sách
     *
     * Tiền phạt: correlated subquery trong SELECT — tránh cartesian product khi LEFT JOIN fines
     *   (một bản sao có thể có nhiều khoản phạt → SUM + GROUP BY sẽ làm phức tạp query chính)
     *
     * Không N+1: toàn bộ dữ liệu lấy trong 1 query duy nhất.
     */
    public function getOverdueBooks(?string $fromDate, ?string $toDate, ?string $status): array
    {
        $rows = DB::table('borrow_transactions as bt')
            ->join('users as u',        'u.user_id',    '=', 'bt.user_id')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc',  'bc.copy_id',   '=', 'bd.copy_id')
            ->join('books as b',         'b.book_id',    '=', 'bc.book_id')
            ->where('bt.status', 'borrowing')
            ->whereRaw('bt.due_date < CURDATE()')
            ->whereNull('bd.return_date')
            ->when($fromDate, fn ($q) => $q->where('bt.due_date', '>=', $fromDate))
            ->when($toDate,   fn ($q) => $q->where('bt.due_date', '<=', $toDate))
            ->when($status === 'low',    fn ($q) => $q->whereRaw('DATEDIFF(CURDATE(), bt.due_date) BETWEEN 1 AND 7'))
            ->when($status === 'medium', fn ($q) => $q->whereRaw('DATEDIFF(CURDATE(), bt.due_date) BETWEEN 8 AND 30'))
            ->when($status === 'high',   fn ($q) => $q->whereRaw('DATEDIFF(CURDATE(), bt.due_date) > 30'))
            ->select([
                'bt.borrow_id',
                'u.full_name as reader_name',
                'u.email as reader_email',
                'b.title as book_title',
                'bt.due_date',
                DB::raw('DATEDIFF(CURDATE(), bt.due_date) AS overdue_days'),
                DB::raw("CASE
                    WHEN DATEDIFF(CURDATE(), bt.due_date) BETWEEN 1 AND 7  THEN 'low'
                    WHEN DATEDIFF(CURDATE(), bt.due_date) BETWEEN 8 AND 30 THEN 'medium'
                    ELSE 'high'
                END AS status"),
                DB::raw('(SELECT COALESCE(SUM(f.amount), 0) FROM fines f WHERE f.borrow_id = bt.borrow_id AND f.copy_id = bd.copy_id) AS fine_amount'),
            ])
            ->orderByRaw('DATEDIFF(CURDATE(), bt.due_date) DESC')
            ->get();

        return $rows->map(fn ($row) => [
            'borrow_id'    => $row->borrow_id,
            'reader_name'  => $row->reader_name,
            'reader_email' => $row->reader_email,
            'book_title'   => $row->book_title,
            'due_date'     => $row->due_date,
            'overdue_days' => (int) $row->overdue_days,
            'status'       => $row->status,
            'fine_amount'  => (float) $row->fine_amount,
        ])->values()->toArray();
    }

    /**
     * Phase 4 — Thống kê phiếu mượn quá hạn theo 3 nhóm ngày (real-time snapshot).
     *
     * Chiến lược: 1 query GROUP BY CASE expression.
     *
     * CASE trong GROUP BY (MySQL / TiDB hỗ trợ alias GROUP BY):
     *   range_key = '1-7'  khi overdue_days BETWEEN 1 AND 7
     *   range_key = '8-30' khi overdue_days BETWEEN 8 AND 30
     *   range_key = '30+'  khi overdue_days > 30
     *
     * PHP keyBy('range_key') → truy cập O(1) theo key.
     * Luôn trả về 3 phần tử (kể cả khi count = 0) để frontend không cần xử lý mảng thiếu.
     */
    public function getOverdueSummary(): array
    {
        $rows = DB::table('borrow_transactions as bt')
            ->where('bt.status', 'borrowing')
            ->whereRaw('bt.due_date < CURDATE()')
            ->selectRaw("
                CASE
                    WHEN DATEDIFF(CURDATE(), bt.due_date) BETWEEN 1 AND 7  THEN '1-7'
                    WHEN DATEDIFF(CURDATE(), bt.due_date) BETWEEN 8 AND 30 THEN '8-30'
                    ELSE '30+'
                END AS range_key,
                COUNT(*) AS cnt
            ")
            ->groupBy('range_key')
            ->get()
            ->keyBy('range_key');

        return [
            ['range_key' => '1-7',  'label' => 'Quá hạn 1–7 ngày',     'count' => (int) ($rows['1-7']->cnt  ?? 0)],
            ['range_key' => '8-30', 'label' => 'Quá hạn 8–30 ngày',    'count' => (int) ($rows['8-30']->cnt ?? 0)],
            ['range_key' => '30+',  'label' => 'Quá hạn trên 30 ngày', 'count' => (int) ($rows['30+']->cnt  ?? 0)],
        ];
    }

    /**
     * Phase 2 (mở rộng) — Top tác giả có sách được mượn nhiều nhất.
     *
     * 1 query duy nhất, không N+1.
     *
     * Chuỗi JOIN:
     *   borrow_details (bd) — mỗi hàng = 1 bản sao được mượn
     *       ↓ bd.borrow_id = bt.borrow_id
     *   borrow_transactions (bt) — có borrow_date để filter ngày
     *       ↓ bd.copy_id = bc.copy_id
     *   book_copies (bc) — bản sao thuộc sách nào
     *       ↓ bc.book_id = b.book_id
     *   books (b) — sách thuộc tác giả nào (b.author_id)
     *       ↓ b.author_id = a.author_id
     *   authors (a) — INNER JOIN (loại sách không có tác giả: author_id IS NULL)
     *
     * GROUP BY a.author_id: mỗi nhóm = 1 tác giả
     * COUNT(*): đếm số hàng borrow_details = số lần sách của tác giả được mượn
     * ORDER BY borrow_count DESC: tác giả nhiều lượt nhất lên đầu
     *
     * Tại sao INNER JOIN authors (không LEFT JOIN như getTopBooks):
     *   getTopBooks cần LEFT JOIN vì đang GROUP BY sách — sách không có tác giả vẫn phải
     *   xuất hiện trong kết quả (author_name trả '' thay vì NULL).
     *   getTopAuthors GROUP BY tác giả — sách không có author_id = NULL không thuộc
     *   tác giả nào → INNER JOIN tự loại bỏ, không ảnh hưởng đến borrow_count.
     */
    public function getTopAuthors(string $fromDate, string $toDate, int $limit): array
    {
        $rows = DB::table('borrow_details as bd')
            ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
            ->join('book_copies as bc',         'bc.copy_id',   '=', 'bd.copy_id')
            ->join('books as b',                'b.book_id',    '=', 'bc.book_id')
            ->join('authors as a',              'a.author_id',  '=', 'b.author_id')
            ->select([
                'a.author_id',
                'a.author_name',
                DB::raw('COUNT(DISTINCT b.book_id) AS book_count'),
                DB::raw('COUNT(*) AS borrow_count'),
            ])
            ->whereBetween('bt.borrow_date', [$fromDate, $toDate])
            ->groupBy('a.author_id', 'a.author_name')
            ->orderByDesc('borrow_count')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return $rows->values()->map(function ($row, $index) {
            return [
                'rank'         => $index + 1,
                'author_id'    => $row->author_id,
                'author_name'  => $row->author_name,
                'book_count'   => (int) $row->book_count,
                'borrow_count' => (int) $row->borrow_count,
            ];
        })->all();
    }

    /**
     * Phase 2 (mở rộng) — Top thể loại có sách được mượn nhiều nhất.
     *
     * 1 query duy nhất, không N+1.
     *
     * Chuỗi JOIN:
     *   borrow_details (bd) — mỗi hàng = 1 bản sao được mượn
     *       ↓ bd.borrow_id = bt.borrow_id
     *   borrow_transactions (bt) — có borrow_date để filter ngày
     *       ↓ bd.copy_id = bc.copy_id
     *   book_copies (bc) — bản sao thuộc sách nào
     *       ↓ bc.book_id = b.book_id
     *   books (b) — sách thuộc thể loại nào (qua pivot)
     *       ↓ b.book_id = bcat.book_id
     *   book_categories (bcat) — bảng pivot (1 sách — N thể loại)
     *       ↓ bcat.category_id = c.category_id
     *   categories (c) — tên thể loại
     *
     * GROUP BY c.category_id: mỗi nhóm = 1 thể loại
     * COUNT(*): đếm số cặp (borrow_detail, category) = số lượt sách thuộc thể loại được mượn
     *
     * Semantic COUNT(*) với sách nhiều thể loại:
     *   Sách X thuộc thể loại A và B, được mượn 5 lần → 5 hàng trong borrow_details.
     *   Sau JOIN book_categories: mỗi trong 5 hàng nhân với 2 dòng pivot → 10 hàng tổng.
     *   GROUP BY category_id: category A có 5 hàng (5 lượt mượn), category B có 5 hàng.
     *   → COUNT(*) = số lượt mượn sách thuộc thể loại đó. Đây là semantic đúng:
     *     "thể loại A được mượn bao nhiêu lần?" = 5 lần.
     *
     * Tại sao không cần query thứ 2 (khác getTopBooks):
     *   getTopBooks cần 2 query vì nó GROUP BY book_id và cần lấy category_names
     *   (nhiều categories / sách) → nếu JOIN pivot trong query 1 sẽ làm COUNT bị sai.
     *   getTopCategories GROUP BY category_id — bản thân categories là chiều ta group,
     *   không cần JOIN thêm bảng nào khác → 1 query là đủ.
     */
    public function getTopCategories(string $fromDate, string $toDate, int $limit): array
    {
        $rows = DB::table('borrow_details as bd')
            ->join('borrow_transactions as bt', 'bt.borrow_id',    '=', 'bd.borrow_id')
            ->join('book_copies as bc',         'bc.copy_id',      '=', 'bd.copy_id')
            ->join('books as b',                'b.book_id',       '=', 'bc.book_id')
            ->join('book_categories as bcat',   'bcat.book_id',    '=', 'b.book_id')
            ->join('categories as c',           'c.category_id',   '=', 'bcat.category_id')
            ->select([
                'c.category_id',
                'c.category_name',
                DB::raw('COUNT(DISTINCT b.book_id) AS book_count'),
                DB::raw('COUNT(*) AS borrow_count'),
            ])
            ->whereBetween('bt.borrow_date', [$fromDate, $toDate])
            ->groupBy('c.category_id', 'c.category_name')
            ->orderByDesc('borrow_count')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        return $rows->values()->map(function ($row, $index) {
            return [
                'rank'          => $index + 1,
                'category_id'   => $row->category_id,
                'category_name' => $row->category_name,
                'book_count'    => (int) $row->book_count,
                'borrow_count'  => (int) $row->borrow_count,
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
