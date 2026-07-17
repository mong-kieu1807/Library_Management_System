<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\FineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FineController extends Controller
{
    public function __construct(private FineService $service) {}

    /**
     * GET /private/v1/fees
     * Danh sách phí chưa thu (unpaid / partial) — Feature 1 & 2
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['search', 'type', 'page', 'per_page']);
        return response()->json($this->service->listFines($filters));
    }

    /**
     * POST /private/v1/fees/damage
     * Tạo phí bồi thường hỏng / mất — Feature 2
     */
    public function createDamage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id'      => 'required|integer|exists:users,user_id',
            'copy_id'      => 'required|integer|exists:book_copies,copy_id',
            'borrow_id'    => 'nullable|integer',
            'damage_level' => 'required|in:minor,medium,heavy,lost',
        ]);

        try {
            $result = $this->service->createDamageFine($validated);
            return response()->json([
                'message' => 'Đã tạo phí bồi thường thành công.',
                'data'    => $result,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => 'Không tìm thấy bản sao sách.'], 422);
        }
    }
}
