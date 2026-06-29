<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reportService) {}

    /**
     * GET /api/private/v1/reports/transactions
     *
     * Params (all optional):
     *   from_date  YYYY-MM-DD  default: first day of 5 months ago
     *   to_date    YYYY-MM-DD  default: today
     *   group_by   day|week|month  default: month
     */
    public function transactions(Request $request): JsonResponse
    {
        $groupBy = $request->input('group_by', 'month');

        if (!in_array($groupBy, ['day', 'week', 'month'], true)) {
            return response()->json([
                'code'    => 422,
                'message' => 'group_by phải là day, week hoặc month.',
            ], 422);
        }

        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response()->json([
                'code'    => 422,
                'message' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).',
            ], 422);
        }

        if ($from->gt($to)) {
            return response()->json([
                'code'    => 422,
                'message' => 'from_date phải nhỏ hơn hoặc bằng to_date.',
            ], 422);
        }

        if ($from->diffInMonths($to, true) > 24) {
            return response()->json([
                'code'    => 422,
                'message' => 'Khoảng thời gian tối đa là 24 tháng.',
            ], 422);
        }

        $data = $this->reportService->getTransactionReport(
            $from->toDateString(),
            $to->toDateString(),
            $groupBy
        );

        return response()->json([
            'code'    => 200,
            'results' => ['object' => $data],
        ]);
    }

    /**
     * GET /api/private/v1/reports/top-books
     *
     * Trả về danh sách sách được mượn nhiều nhất trong khoảng thời gian.
     * Params (optional):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   limit      integer     default: 10, max: 50
     */
    public function topBooks(Request $request): JsonResponse
    {
        // Đọc limit — validate trước để tránh parse date khi limit đã sai
        $limit = (int) $request->input('limit', 10);
        if ($limit < 1 || $limit > 50) {
            return response()->json([
                'code'    => 422,
                'message' => 'limit phải từ 1 đến 50.',
            ], 422);
        }

        // Parse ngày — dùng try/catch vì Carbon::parse() có thể ném Exception
        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response()->json([
                'code'    => 422,
                'message' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).',
            ], 422);
        }

        if ($from->gt($to)) {
            return response()->json([
                'code'    => 422,
                'message' => 'from_date phải nhỏ hơn hoặc bằng to_date.',
            ], 422);
        }

        if ($from->diffInMonths($to, true) > 24) {
            return response()->json([
                'code'    => 422,
                'message' => 'Khoảng thời gian tối đa là 24 tháng.',
            ], 422);
        }

        // Delegate toàn bộ business logic cho Service
        $items = $this->reportService->getTopBooks(
            $from->toDateString(),
            $to->toDateString(),
            $limit
        );

        // Response dùng "objects" (array) thay vì "object" (single item) như Phase 1
        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }

    /**
     * GET /api/private/v1/reports/top-readers   (Phase 3A)
     *
     * Trả về danh sách độc giả mượn nhiều nhất trong khoảng thời gian.
     * Params (optional):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   limit      integer     default: 10, max: 50
     */
    public function topReaders(Request $request): JsonResponse
    {
        // Validate limit trước — kiểm tra đơn giản, không cần try/catch
        $limit = (int) $request->input('limit', 10);
        if ($limit < 1 || $limit > 50) {
            return response()->json([
                'code'    => 422,
                'message' => 'limit phải từ 1 đến 50.',
            ], 422);
        }

        // Parse ngày bằng Carbon — throw Exception nếu format sai
        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response()->json([
                'code'    => 422,
                'message' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).',
            ], 422);
        }

        if ($from->gt($to)) {
            return response()->json([
                'code'    => 422,
                'message' => 'from_date phải nhỏ hơn hoặc bằng to_date.',
            ], 422);
        }

        if ($from->diffInMonths($to, true) > 24) {
            return response()->json([
                'code'    => 422,
                'message' => 'Khoảng thời gian tối đa là 24 tháng.',
            ], 422);
        }

        $items = $this->reportService->getTopReaders(
            $from->toDateString(),
            $to->toDateString(),
            $limit
        );

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }

    /**
     * GET /api/private/v1/reports/fine-revenue   (Phase 4 — Fine)
     *
     * Doanh thu tiền phạt theo tháng: tổng tiền thực tế đã thu (payments.payment_date).
     * Params (optional):
     *   from_date  YYYY-MM-DD  default: 12 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     */
    public function fineRevenue(Request $request): JsonResponse
    {
        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->subMonths(11)->startOfMonth();

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response()->json([
                'code'    => 422,
                'message' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).',
            ], 422);
        }

        if ($from->gt($to)) {
            return response()->json([
                'code'    => 422,
                'message' => 'from_date phải nhỏ hơn hoặc bằng to_date.',
            ], 422);
        }

        if ($from->diffInMonths($to, true) > 36) {
            return response()->json([
                'code'    => 422,
                'message' => 'Khoảng thời gian tối đa là 36 tháng.',
            ], 422);
        }

        $items = $this->reportService->getFineRevenue(
            $from->toDateString(),
            $to->toDateString()
        );

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }

    /**
     * GET /api/private/v1/reports/fine-reasons   (Phase 4 — Fine)
     *
     * Thống kê số lượt và tổng tiền phạt theo nhóm nguyên nhân (all-time, không filter ngày).
     * Nhóm nguyên nhân được phân loại bằng CASE LIKE trên cột fines.reason.
     */
    public function fineReasons(): JsonResponse
    {
        $items = $this->reportService->getFineReasons();

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }

    /**
     * GET /api/private/v1/reports/overdue-books   (Phase 4)
     *
     * Trả về danh sách từng bản sao sách đang quá hạn (chưa trả, due_date < hôm nay).
     * Params (tất cả optional):
     *   from_date  YYYY-MM-DD  lọc theo due_date >= from_date
     *   to_date    YYYY-MM-DD  lọc theo due_date <= to_date
     *   status     low|medium|high  lọc theo mức độ nghiêm trọng (1-7 / 8-30 / >30 ngày)
     */
    public function overdueBooks(Request $request): JsonResponse
    {
        $status = $request->input('status');
        if ($status !== null && !in_array($status, ['low', 'medium', 'high'], true)) {
            return response()->json([
                'code'    => 422,
                'message' => 'status phải là low, medium hoặc high.',
            ], 422);
        }

        $fromDate = null;
        $toDate   = null;

        if ($request->filled('from_date') || $request->filled('to_date')) {
            try {
                $from = $request->filled('from_date')
                    ? Carbon::parse($request->input('from_date'))->startOfDay()
                    : null;

                $to = $request->filled('to_date')
                    ? Carbon::parse($request->input('to_date'))->endOfDay()
                    : null;
            } catch (\Exception) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).',
                ], 422);
            }

            if ($from && $to && $from->gt($to)) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'from_date phải nhỏ hơn hoặc bằng to_date.',
                ], 422);
            }

            if ($from && $to && $from->diffInMonths($to, true) > 24) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'Khoảng thời gian tối đa là 24 tháng.',
                ], 422);
            }

            $fromDate = $from?->toDateString();
            $toDate   = $to?->toDateString();
        }

        $items = $this->reportService->getOverdueBooks($fromDate, $toDate, $status);

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }

    /**
     * GET /api/private/v1/reports/overdue-summary   (Phase 4)
     *
     * Trả về thống kê số phiếu mượn quá hạn theo 3 nhóm ngày (real-time, không cần filter ngày).
     *   Nhóm 1: 1–7 ngày
     *   Nhóm 2: 8–30 ngày
     *   Nhóm 3: trên 30 ngày
     */
    public function overdueSummary(): JsonResponse
    {
        $items = $this->reportService->getOverdueSummary();

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }

    /**
     * GET /api/private/v1/reports/top-authors   (Phase 2 — mở rộng)
     *
     * Trả về danh sách tác giả có sách được mượn nhiều nhất trong khoảng thời gian.
     * Params (optional):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   limit      integer     default: 10, max: 50
     */
    public function topAuthors(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 10);
        if ($limit < 1 || $limit > 50) {
            return response()->json([
                'code'    => 422,
                'message' => 'limit phải từ 1 đến 50.',
            ], 422);
        }

        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response()->json([
                'code'    => 422,
                'message' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).',
            ], 422);
        }

        if ($from->gt($to)) {
            return response()->json([
                'code'    => 422,
                'message' => 'from_date phải nhỏ hơn hoặc bằng to_date.',
            ], 422);
        }

        if ($from->diffInMonths($to, true) > 24) {
            return response()->json([
                'code'    => 422,
                'message' => 'Khoảng thời gian tối đa là 24 tháng.',
            ], 422);
        }

        $items = $this->reportService->getTopAuthors(
            $from->toDateString(),
            $to->toDateString(),
            $limit
        );

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }

    /**
     * GET /api/private/v1/reports/top-categories   (Phase 2 — mở rộng)
     *
     * Trả về danh sách thể loại có sách được mượn nhiều nhất trong khoảng thời gian.
     * Params (optional):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   limit      integer     default: 10, max: 50
     */
    public function topCategories(Request $request): JsonResponse
    {
        $limit = (int) $request->input('limit', 10);
        if ($limit < 1 || $limit > 50) {
            return response()->json([
                'code'    => 422,
                'message' => 'limit phải từ 1 đến 50.',
            ], 422);
        }

        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response()->json([
                'code'    => 422,
                'message' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).',
            ], 422);
        }

        if ($from->gt($to)) {
            return response()->json([
                'code'    => 422,
                'message' => 'from_date phải nhỏ hơn hoặc bằng to_date.',
            ], 422);
        }

        if ($from->diffInMonths($to, true) > 24) {
            return response()->json([
                'code'    => 422,
                'message' => 'Khoảng thời gian tối đa là 24 tháng.',
            ], 422);
        }

        $items = $this->reportService->getTopCategories(
            $from->toDateString(),
            $to->toDateString(),
            $limit
        );

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }

    /**
     * GET /api/private/v1/reports/reader-registrations   (Phase 3B)
     *
     * Trả về xu hướng đăng ký độc giả mới theo tháng.
     * Params (optional):
     *   from_date  YYYY-MM-DD  default: 12 tháng trước (tháng hiện tại + 11 tháng trước)
     *   to_date    YYYY-MM-DD  default: hôm nay
     *
     * Không có limit (trả toàn bộ timeline) và không có group_by (luôn theo tháng).
     */
    public function readerRegistrations(Request $request): JsonResponse
    {
        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->subMonths(11)->startOfMonth();

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response()->json([
                'code'    => 422,
                'message' => 'Định dạng ngày không hợp lệ (YYYY-MM-DD).',
            ], 422);
        }

        if ($from->gt($to)) {
            return response()->json([
                'code'    => 422,
                'message' => 'from_date phải nhỏ hơn hoặc bằng to_date.',
            ], 422);
        }

        // Registration trend cho phép xem tối đa 36 tháng (3 năm học)
        if ($from->diffInMonths($to, true) > 36) {
            return response()->json([
                'code'    => 422,
                'message' => 'Khoảng thời gian tối đa là 36 tháng.',
            ], 422);
        }

        $items = $this->reportService->getReaderRegistrationTrend(
            $from->toDateString(),
            $to->toDateString()
        );

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $items],
        ]);
    }
}
