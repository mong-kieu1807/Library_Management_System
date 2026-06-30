<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExportController extends Controller
{
    public function __construct(private readonly ReportService $reportService) {}

    /**
     * GET /api/private/v1/reports/export/overdue-pdf
     *
     * Xuất PDF báo cáo sách quá hạn.
     * Route nằm NGOÀI auth middleware vì window.open() không thể gửi Authorization header.
     *
     * Params (optional — giống ReportController::overdueBooks):
     *   from_date  YYYY-MM-DD  lọc theo due_date >= from_date
     *   to_date    YYYY-MM-DD  lọc theo due_date <= to_date
     *   status     low|medium|high  lọc theo mức độ nghiêm trọng
     */
    public function overdueBooksPdf(Request $request): \Illuminate\Http\Response
    {
        // ── 1. Validate status ────────────────────────────────────────────────
        $status = $request->input('status');
        if ($status !== null && !in_array($status, ['low', 'medium', 'high'], true)) {
            return response('status phải là low, medium hoặc high.', 422);
        }

        // ── 2. Parse date params ──────────────────────────────────────────────
        $fromDate = null;
        $toDate   = null;
        $fromLabel = 'Toàn bộ';
        $toLabel   = '';

        if ($request->filled('from_date') || $request->filled('to_date')) {
            try {
                $from = $request->filled('from_date')
                    ? Carbon::parse($request->input('from_date'))->startOfDay()
                    : null;

                $to = $request->filled('to_date')
                    ? Carbon::parse($request->input('to_date'))->endOfDay()
                    : null;
            } catch (\Exception) {
                return response('Định dạng ngày không hợp lệ (YYYY-MM-DD).', 422);
            }

            if ($from && $to && $from->gt($to)) {
                return response('from_date phải nhỏ hơn hoặc bằng to_date.', 422);
            }

            if ($from && $to && $from->diffInMonths($to, true) > 24) {
                return response('Khoảng thời gian tối đa là 24 tháng.', 422);
            }

            $fromDate  = $from?->toDateString();
            $toDate    = $to?->toDateString();
            $fromLabel = $from ? $from->format('d/m/Y') : 'Không giới hạn';
            $toLabel   = $to   ? $to->format('d/m/Y')  : 'Không giới hạn';
        }

        // ── 3. Lấy dữ liệu từ ReportService (tái sử dụng, không query mới) ──
        $items = $this->reportService->getOverdueBooks($fromDate, $toDate, $status);

        // ── 4. Lấy thông tin thư viện từ system_settings ─────────────────────
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['library_name', 'address', 'contact_phone'])
            ->pluck('config_value', 'config_key');

        // ── 5. Label mức độ để hiển thị trong PDF ────────────────────────────
        $statusLabel = match ($status) {
            'low'    => 'Quá hạn nhẹ (1–7 ngày)',
            'medium' => 'Quá hạn vừa (8–30 ngày)',
            'high'   => 'Quá hạn nặng (>30 ngày)',
            default  => 'Tất cả mức độ',
        };

        // ── 6. Build data cho Blade view ─────────────────────────────────────
        $data = [
            'items'        => $items,
            'total'        => count($items),
            'libraryName'  => $settings['library_name']   ?? 'Thư Viện',
            'address'      => $settings['address']         ?? '',
            'phone'        => $settings['contact_phone']   ?? '',
            'generatedAt'  => now()->format('d/m/Y H:i'),
            'fromLabel'    => $fromLabel,
            'toLabel'      => $toLabel,
            'statusLabel'  => $statusLabel,
        ];

        // ── 7. Render PDF ─────────────────────────────────────────────────────
        $pdf = Pdf::loadView('reports.overdue_pdf', $data);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions($this->dompdfOptions());

        return $this->wrapPdfAsHtml(
            $pdf->output(),
            'Báo cáo sách quá hạn'
        );
    }

    /**
     * GET /api/private/v1/reports/export/transactions-pdf
     *
     * Xuất PDF báo cáo giao dịch mượn/trả.
     * Route nằm NGOÀI auth middleware — window.open() không thể gửi Authorization header.
     *
     * Params (optional — giống ReportController::transactions):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   group_by   day|week|month  default: month
     */
    public function transactionsPdf(Request $request): \Illuminate\Http\Response
    {
        // ── 1. Validate group_by ──────────────────────────────────────────────
        $groupBy = $request->input('group_by', 'month');
        if (!in_array($groupBy, ['day', 'week', 'month'], true)) {
            return response('group_by phải là day, week hoặc month.', 422);
        }

        // ── 2. Parse date params (defaults giống ReportController::transactions) ─
        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response('Định dạng ngày không hợp lệ (YYYY-MM-DD).', 422);
        }

        // ── 3. Validate from ≤ to ─────────────────────────────────────────────
        if ($from->gt($to)) {
            return response('from_date phải nhỏ hơn hoặc bằng to_date.', 422);
        }

        // ── 4. Validate max 24 tháng ──────────────────────────────────────────
        if ($from->diffInMonths($to, true) > 24) {
            return response('Khoảng thời gian tối đa là 24 tháng.', 422);
        }

        // ── 5. Lấy dữ liệu — reuse getTransactionReport(), không query mới ───
        $reportData = $this->reportService->getTransactionReport(
            $from->toDateString(),
            $to->toDateString(),
            $groupBy
        );

        // ── 6. Lấy thông tin thư viện ────────────────────────────────────────
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['library_name', 'address', 'contact_phone'])
            ->pluck('config_value', 'config_key');

        // ── 7. Label group_by để hiển thị trong PDF ───────────────────────────
        $groupByLabel = match ($groupBy) {
            'day'   => 'Theo ngày',
            'week'  => 'Theo tuần',
            default => 'Theo tháng',
        };

        // ── 8. Build data cho Blade view ──────────────────────────────────────
        $data = [
            'summary'      => $reportData['summary'],   // total_borrows, total_returns, active_borrows, overdue
            'chart'        => $reportData['chart'],      // [{period, label, borrows, returns}, ...]
            'libraryName'  => $settings['library_name']  ?? 'Thư Viện',
            'address'      => $settings['address']        ?? '',
            'phone'        => $settings['contact_phone']  ?? '',
            'generatedAt'  => now()->format('d/m/Y H:i'),
            'fromLabel'    => $from->format('d/m/Y'),
            'toLabel'      => $to->format('d/m/Y'),
            'groupByLabel' => $groupByLabel,
        ];

        // ── 9. Render PDF ─────────────────────────────────────────────────────
        $pdf = Pdf::loadView('reports.transactions_pdf', $data);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions($this->dompdfOptions());

        return $this->wrapPdfAsHtml(
            $pdf->output(),
            'Báo cáo giao dịch mượn/trả'
        );
    }

    /**
     * GET /api/private/v1/reports/export/fine-report-pdf
     *
     * Xuất PDF báo cáo doanh thu tiền phạt — kết hợp 2 section:
     *   Section 1: doanh thu theo tháng (lọc theo from_date/to_date)
     *   Section 2: phân loại nguyên nhân (all-time — getFineReasons không có date filter)
     *
     * Route nằm NGOÀI auth middleware — window.open() không thể gửi Authorization header.
     *
     * Params (optional — giống ReportController::fineRevenue):
     *   from_date  YYYY-MM-DD  default: đầu tháng 11 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     */
    public function fineReportPdf(Request $request): \Illuminate\Http\Response
    {
        // ── 1. Parse date params (defaults giống ReportController::fineRevenue) ─
        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->subMonths(11)->startOfMonth();

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            return response('Định dạng ngày không hợp lệ (YYYY-MM-DD).', 422);
        }

        // ── 2. Validate from ≤ to ─────────────────────────────────────────────
        if ($from->gt($to)) {
            return response('from_date phải nhỏ hơn hoặc bằng to_date.', 422);
        }

        // ── 3. Validate max 36 tháng (giống endpoint /fine-revenue) ──────────
        if ($from->diffInMonths($to, true) > 36) {
            return response('Khoảng thời gian tối đa là 36 tháng.', 422);
        }

        // ── 4. Lấy dữ liệu — reuse 2 methods, không query mới ────────────────
        $revenue = $this->reportService->getFineRevenue(
            $from->toDateString(),
            $to->toDateString()
        );
        $reasons = $this->reportService->getFineReasons();

        // ── 5. Tổng hợp summary (tính trong PHP, không cần query thêm) ────────
        $totalRevenue   = array_sum(array_column($revenue, 'revenue'));
        $totalFineCount = array_sum(array_column($revenue, 'fine_count'));

        // ── 6. Lấy thông tin thư viện ────────────────────────────────────────
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['library_name', 'address', 'contact_phone'])
            ->pluck('config_value', 'config_key');

        // ── 7. Build data cho Blade view ──────────────────────────────────────
        $data = [
            'revenue'        => $revenue,          // [{period,label,revenue,fine_count},...]
            'reasons'        => $reasons,           // [{category,fine_count,total_amount},...]  — all-time
            'totalRevenue'   => $totalRevenue,
            'totalFineCount' => $totalFineCount,
            'libraryName'    => $settings['library_name']  ?? 'Thư Viện',
            'address'        => $settings['address']        ?? '',
            'phone'          => $settings['contact_phone']  ?? '',
            'generatedAt'    => now()->format('d/m/Y H:i'),
            'fromLabel'      => $from->format('d/m/Y'),
            'toLabel'        => $to->format('d/m/Y'),
        ];

        // ── 8. Render PDF ─────────────────────────────────────────────────────
        $pdf = Pdf::loadView('reports.fine_report_pdf', $data);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions($this->dompdfOptions());

        return $this->wrapPdfAsHtml(
            $pdf->output(),
            'Báo cáo doanh thu tiền phạt'
        );
    }

    /**
     * GET /api/private/v1/reports/export/transactions-csv
     *
     * Xuất CSV báo cáo giao dịch mượn/trả — có thể mở trực tiếp trong Excel.
     * Route nằm NGOÀI auth middleware — window.open() không thể gửi Authorization header.
     *
     * Params (optional — giống transactionsPdf):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   group_by   day|week|month  default: month
     *
     * CSV columns: Kỳ | Lượt mượn | Lượt trả
     * Cuối file:   dòng trống + dòng tổng cộng
     * Encoding:    UTF-8 BOM (Excel cần để hiển thị tiếng Việt đúng)
     */
    public function transactionsCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // ── 1. Validate group_by ──────────────────────────────────────────────
        $groupBy = $request->input('group_by', 'month');
        if (!in_array($groupBy, ['day', 'week', 'month'], true)) {
            abort(422, 'group_by phải là day, week hoặc month.');
        }

        // ── 2. Parse date params (defaults giống transactionsPdf) ─────────────
        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            abort(422, 'Định dạng ngày không hợp lệ (YYYY-MM-DD).');
        }

        // ── 3. Validate from ≤ to ─────────────────────────────────────────────
        if ($from->gt($to)) {
            abort(422, 'from_date phải nhỏ hơn hoặc bằng to_date.');
        }

        // ── 4. Validate max 24 tháng ──────────────────────────────────────────
        if ($from->diffInMonths($to, true) > 24) {
            abort(422, 'Khoảng thời gian tối đa là 24 tháng.');
        }

        // ── 5. Lấy dữ liệu — reuse getTransactionReport(), không query mới ───
        $reportData = $this->reportService->getTransactionReport(
            $from->toDateString(),
            $to->toDateString(),
            $groupBy
        );

        $chart   = $reportData['chart'];    // [{period, label, borrows, returns}, ...]
        $summary = $reportData['summary'];  // {total_borrows, total_returns, active_borrows, overdue}

        // ── 6. Build filename ──────────────────────────────────────────────────
        $filename = 'bao-cao-giao-dich-' . now()->format('Ymd') . '.csv';

        // ── 7. Stream CSV response ────────────────────────────────────────────
        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        return response()->stream(function () use ($chart, $summary) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM — Excel cần BOM để nhận dạng file UTF-8 và hiển thị đúng tiếng Việt
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // Header row
            fputcsv($file, ['Kỳ', 'Lượt mượn', 'Lượt trả']);

            // Data rows — label từ chart (T1/2025, 01/03, T13/2025...)
            foreach ($chart as $row) {
                fputcsv($file, [$row['label'], $row['borrows'], $row['returns']]);
            }

            // Dòng trống phân cách
            fputcsv($file, []);

            // Dòng tổng — dùng summary (đã có từ getTransactionReport, không tính lại)
            fputcsv($file, ['Tổng cộng', $summary['total_borrows'], $summary['total_returns']]);

            fclose($file);
        }, 200, $headers);
    }

    /**
     * GET /api/private/v1/reports/export/top-books-csv
     *
     * Xuất CSV Top sách được mượn nhiều nhất — mở trực tiếp trong Excel.
     * Route NGOÀI auth middleware — window.open() không thể gửi Authorization header.
     *
     * Params (optional — giống ReportController::topBooks):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   limit      integer     default: 10, max: 50
     *
     * CSV columns: Xếp hạng | Tên sách | Tác giả | Thể loại | Số lượt mượn
     */
    public function topBooksCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        // ── 1. Validate limit ─────────────────────────────────────────────────
        $limit = (int) $request->input('limit', 10);
        if ($limit < 1 || $limit > 50) {
            abort(422, 'limit phải từ 1 đến 50.');
        }

        // ── 2. Parse date params ──────────────────────────────────────────────
        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            abort(422, 'Định dạng ngày không hợp lệ (YYYY-MM-DD).');
        }

        // ── 3. Validate ───────────────────────────────────────────────────────
        if ($from->gt($to)) abort(422, 'from_date phải nhỏ hơn hoặc bằng to_date.');
        if ($from->diffInMonths($to, true) > 24) abort(422, 'Khoảng thời gian tối đa là 24 tháng.');

        // ── 4. Lấy dữ liệu — reuse getTopBooks(), không query mới ────────────
        $items = $this->reportService->getTopBooks(
            $from->toDateString(),
            $to->toDateString(),
            $limit
        );

        // ── 5. Stream CSV ──────────────────────────────────────────────────────
        $filename = 'top-sach-' . now()->format('Ymd') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $totalBorrows = array_sum(array_column($items, 'borrow_count'));
        $totalBooks   = count($items);

        return response()->stream(function () use ($items, $totalBooks, $totalBorrows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));   // UTF-8 BOM

            fputcsv($file, ['Xếp hạng', 'Tên sách', 'Tác giả', 'Thể loại', 'Số lượt mượn']);

            foreach ($items as $row) {
                fputcsv($file, [
                    $row['rank'],
                    $row['title'],
                    $row['author_name'] ?: '—',
                    $row['category_names'] ?: '—',
                    $row['borrow_count'],
                ]);
            }

            fputcsv($file, []);
            fputcsv($file, ['Tổng số sách:', $totalBooks]);
            fputcsv($file, ['Tổng lượt mượn:', $totalBorrows]);

            fclose($file);
        }, 200, $headers);
    }

    /**
     * GET /api/private/v1/reports/export/top-authors-csv
     *
     * Xuất CSV Top tác giả có sách được mượn nhiều nhất — mở trực tiếp trong Excel.
     * Route NGOÀI auth middleware.
     *
     * Params (optional — giống ReportController::topAuthors):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   limit      integer     default: 10, max: 50
     *
     * CSV columns: Xếp hạng | Tác giả | Số lượt mượn
     * (Không có "Số đầu sách" — getTopAuthors() không trả về book_count;
     *  thêm sẽ cần sửa service → vi phạm constraint không refactor Phase 2.)
     */
    public function topAuthorsCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $limit = (int) $request->input('limit', 10);
        if ($limit < 1 || $limit > 50) {
            abort(422, 'limit phải từ 1 đến 50.');
        }

        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            abort(422, 'Định dạng ngày không hợp lệ (YYYY-MM-DD).');
        }

        if ($from->gt($to)) abort(422, 'from_date phải nhỏ hơn hoặc bằng to_date.');
        if ($from->diffInMonths($to, true) > 24) abort(422, 'Khoảng thời gian tối đa là 24 tháng.');

        $items = $this->reportService->getTopAuthors(
            $from->toDateString(),
            $to->toDateString(),
            $limit
        );

        $filename = 'top-tac-gia-' . now()->format('Ymd') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $totalBorrows  = array_sum(array_column($items, 'borrow_count'));
        $totalAuthors  = count($items);

        return response()->stream(function () use ($items, $totalAuthors, $totalBorrows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));   // UTF-8 BOM

            fputcsv($file, ['Xếp hạng', 'Tác giả', 'Số đầu sách', 'Số lượt mượn']);

            foreach ($items as $row) {
                fputcsv($file, [
                    $row['rank'],
                    $row['author_name'],
                    $row['book_count'],
                    $row['borrow_count'],
                ]);
            }

            fputcsv($file, []);
            fputcsv($file, ['Tổng tác giả:', $totalAuthors]);
            fputcsv($file, ['Tổng lượt mượn:', $totalBorrows]);

            fclose($file);
        }, 200, $headers);
    }

    /**
     * GET /api/private/v1/reports/export/top-categories-csv
     *
     * Xuất CSV Top thể loại có sách được mượn nhiều nhất — mở trực tiếp trong Excel.
     * Route NGOÀI auth middleware.
     *
     * Params (optional — giống ReportController::topCategories):
     *   from_date  YYYY-MM-DD  default: đầu tháng 5 tháng trước
     *   to_date    YYYY-MM-DD  default: hôm nay
     *   limit      integer     default: 10, max: 50
     *
     * CSV columns: Xếp hạng | Thể loại | Số lượt mượn
     * (Không có "Số đầu sách" — cùng lý do topAuthorsCsv.)
     */
    public function topCategoriesCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $limit = (int) $request->input('limit', 10);
        if ($limit < 1 || $limit > 50) {
            abort(422, 'limit phải từ 1 đến 50.');
        }

        try {
            $from = $request->filled('from_date')
                ? Carbon::parse($request->input('from_date'))->startOfDay()
                : Carbon::now()->startOfMonth()->subMonths(5);

            $to = $request->filled('to_date')
                ? Carbon::parse($request->input('to_date'))->endOfDay()
                : Carbon::today()->endOfDay();
        } catch (\Exception) {
            abort(422, 'Định dạng ngày không hợp lệ (YYYY-MM-DD).');
        }

        if ($from->gt($to)) abort(422, 'from_date phải nhỏ hơn hoặc bằng to_date.');
        if ($from->diffInMonths($to, true) > 24) abort(422, 'Khoảng thời gian tối đa là 24 tháng.');

        $items = $this->reportService->getTopCategories(
            $from->toDateString(),
            $to->toDateString(),
            $limit
        );

        $filename = 'top-the-loai-' . now()->format('Ymd') . '.csv';
        $headers  = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $totalBorrows     = array_sum(array_column($items, 'borrow_count'));
        $totalCategories  = count($items);

        return response()->stream(function () use ($items, $totalCategories, $totalBorrows) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));   // UTF-8 BOM

            fputcsv($file, ['Xếp hạng', 'Thể loại', 'Số đầu sách', 'Số lượt mượn']);

            foreach ($items as $row) {
                fputcsv($file, [
                    $row['rank'],
                    $row['category_name'],
                    $row['book_count'],
                    $row['borrow_count'],
                ]);
            }

            fputcsv($file, []);
            fputcsv($file, ['Tổng thể loại:', $totalCategories]);
            fputcsv($file, ['Tổng lượt mượn:', $totalBorrows]);

            fclose($file);
        }, 200, $headers);
    }

    // ── Private helpers (cùng pattern với ReceiptService) ────────────────────

    /**
     * DomPDF options — trỏ đúng fontDir và fontCache để load DejaVu Sans (hỗ trợ tiếng Việt).
     */
    private function dompdfOptions(): array
    {
        return [
            'fontDir'         => base_path('vendor/dompdf/dompdf/lib/fonts'),
            'fontCache'       => storage_path('fonts'),
            'defaultFont'     => 'DejaVu Sans',
            'isRemoteEnabled' => false,
        ];
    }

    /**
     * Bọc PDF binary trong HTML wrapper để bypass IDM (Internet Download Manager).
     * IDM chặn Content-Type: application/pdf, không chặn text/html.
     * JS trong trang tạo blob URL từ base64 và nhúng vào <iframe>.
     */
    private function wrapPdfAsHtml(string $pdfBinary, string $title): \Illuminate\Http\Response
    {
        $base64    = base64_encode($pdfBinary);
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>{$safeTitle}</title>
  <style>
    * { margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; overflow: hidden; background: #525659; }
    iframe { display: block; width: 100%; height: 100%; border: none; }
  </style>
</head>
<body>
  <iframe id="v"></iframe>
  <script>
    (function () {
      var b64 = "{$base64}";
      var raw = atob(b64);
      var arr = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
      var blob = new Blob([arr], { type: "application/pdf" });
      document.getElementById("v").src = URL.createObjectURL(blob);
    })();
  </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
