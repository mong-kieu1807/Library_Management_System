<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private FineService $service) {}

    /**
     * POST /private/v1/fees/{id}/pay
     * Ghi nhận thanh toán tại quầy — Feature 3
     */
    public function recordPayment(int $id, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'method' => 'required|in:cash,transfer,momo',
        ]);

        try {
            $result = $this->service->recordPayment($id, $validated['method']);
            return response()->json([
                'message' => 'Ghi nhận thanh toán thành công.',
                'data'    => $result,
            ]);
        } catch (\RuntimeException $e) {
            $msg = match (true) {
                str_contains($e->getMessage(), 'not found')            => 'Không tìm thấy khoản phí.',
                str_contains($e->getMessage(), 'already paid')         => 'Khoản phí này đã được thanh toán.',
                str_contains($e->getMessage(), 'no borrow data')       => 'Không tìm thấy dữ liệu mượn/trả tương ứng với khoản phí trễ hạn này.',
                str_contains($e->getMessage(), 'not actually overdue') => 'Khoản phí trễ hạn này chưa thực sự quá hạn theo dữ liệu mượn/trả hiện tại — vui lòng kiểm tra lại due_date/return_date trước khi xác nhận thanh toán.',
                str_contains($e->getMessage(), 'payment already exists') => 'Khoản phí này đã có giao dịch thanh toán, không thể tạo thêm.',
                default => 'Có lỗi xảy ra.',
            };
            return response()->json(['message' => $msg], 422);
        }
    }

    /**
     * GET /private/v1/fees/history
     * Lịch sử thu phí — Feature 4
     */
    public function history(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'date_from', 'date_to', 'method', 'page', 'per_page']);
        return response()->json($this->service->getHistory($filters));
    }

    /**
     * GET /private/v1/fees/revenue?year=2026&group_by=month
     * Báo cáo doanh thu phí — Feature 5
     */
    public function revenue(Request $request): JsonResponse
    {
        $year    = (int) $request->query('year',     now()->year);
        $groupBy = $request->query('group_by', 'month');

        if (!in_array($groupBy, ['day', 'month'])) {
            return response()->json(['message' => 'group_by phải là day hoặc month.'], 422);
        }

        return response()->json($this->service->getRevenue($year, $groupBy));
    }
}
