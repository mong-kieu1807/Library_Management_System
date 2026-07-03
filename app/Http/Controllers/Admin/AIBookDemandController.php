<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AIBookDemandService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AIBookDemandController extends Controller
{
    public function __construct(
        private AIBookDemandService $service
    ) {}

    /**
     * GET /private/v1/ai/import-suggestions
     *
     * Feature 1: Gợi ý nhập thêm sách (Gap Analysis)
     * - Từ search_logs: từ khóa tìm không có kết quả
     * - Từ wishlists: sách muốn đọc nhưng hết bản
     */
    public function importSuggestions(): JsonResponse
    {
        $data = $this->service->getImportSuggestions();
        return response()->json($data);
    }

    /**
     * GET /private/v1/ai/low-borrow-books
     *
     * Feature 2: Phát hiện sách ít được mượn (không mượn trong 12 tháng)
     * - Gợi ý: Thanh lý (>24 tháng) hoặc Chuyển kho (12–24 tháng)
     */
    public function lowBorrowBooks(): JsonResponse
    {
        $data = $this->service->getLowBorrowBooks();
        return response()->json($data);
    }

    /**
     * GET /private/v1/ai/seasonal-demand?year=2025
     *
     * Feature 3: Dự báo nhu cầu theo mùa
     * - Group by month + category
     * - Dùng cho chart bar/line trên dashboard
     */
    public function seasonalDemand(Request $request): JsonResponse
    {
        $year = (int) $request->query('year', now()->year);

        if ($year < 2000 || $year > now()->year + 1) {
            return response()->json(['message' => 'Năm không hợp lệ.'], 422);
        }

        $data = $this->service->getSeasonalDemand($year);
        return response()->json($data);
    }

    /**
     * POST /private/v1/ai/cache/clear
     *
     * Xóa cache thủ công (admin only)
     */
    public function clearCache(Request $request): JsonResponse
    {
        $year = $request->integer('year') ?: null;
        $this->service->clearCache($year);
        return response()->json(['message' => 'Cache đã được xóa.']);
    }
}
