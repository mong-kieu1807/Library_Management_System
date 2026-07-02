<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LibraryCardController extends Controller
{
    public function show(int $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'Người dùng không tồn tại.'], 404);
        }

        $card = DB::table('library_cards')
            ->where('user_id', $userId)
            ->first();

        if (!$card) {
            return response()->json([
                'message' => 'Bạn chưa được cấp thẻ thư viện.',
            ], 404);
        }

        if ($card->status == 0) {
            $cardStatus = 'Bị khóa';
        } elseif (Carbon::parse($card->expiry_date)->lt(Carbon::today())) {
            $cardStatus = 'Hết hạn';
        } else {
            $cardStatus = 'Hợp lệ';
        }

        return response()->json([
            'card_id'         => $card->card_id,
            'card_number'     => $card->card_number,
            'issue_date'      => $card->issue_date,
            'expiry_date'     => $card->expiry_date,
            'borrow_limit'    => $card->borrow_limit,
            'max_borrow_days' => $card->max_borrow_days,
            'card_status'     => $cardStatus,
        ]);
    }

    /**
     * POST /v1/me/library-card/renewal-request
     *
     * Reader gửi yêu cầu gia hạn thẻ thư viện.
     *
     * Validation:
     *  - Thẻ tồn tại và thuộc về user đang đăng nhập
     *  - Thẻ không bị khóa (status = 0)
     *  - Chưa có request pending
     */
    public function submitRenewalRequest(Request $request)
    {
        $userId = auth()->id();

        $card = DB::table('library_cards')
            ->where('user_id', $userId)
            ->first();

        if (!$card) {
            return response()->json(['message' => 'Bạn chưa được cấp thẻ thư viện.'], 404);
        }

        if ($card->status == 0) {
            return response()->json(['message' => 'Thẻ thư viện của bạn đang bị khóa, không thể gia hạn.'], 422);
        }

        $hasPending = DB::table('card_renewal_requests')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return response()->json(['message' => 'Bạn đã có yêu cầu gia hạn thẻ đang chờ duyệt.'], 422);
        }

        // Tính ngày hết hạn mới: cộng thêm 1 năm từ ngày hết hạn hiện tại hoặc từ hôm nay
        $currentExpiry  = Carbon::parse($card->expiry_date);
        $baseDate       = $currentExpiry->isFuture() ? $currentExpiry : Carbon::today();
        $requestedExpiry = $baseDate->addYear()->toDateString();

        $requestId = DB::table('card_renewal_requests')->insertGetId([
            'card_id'               => $card->card_id,
            'user_id'               => $userId,
            'requested_expiry_date' => $requestedExpiry,
            'status'                => 'pending',
            'requested_at'          => now(),
        ]);

        return response()->json([
            'message'    => 'Yêu cầu gia hạn thẻ đã được gửi, đang chờ thư viện duyệt.',
            'request_id' => $requestId,
        ], 201);
    }

    /**
     * GET /v1/me/library-card/renewal-requests
     *
     * Reader xem danh sách yêu cầu gia hạn thẻ của mình.
     */
    public function myRenewalRequests()
    {
        $userId = auth()->id();

        $rows = DB::table('card_renewal_requests')
            ->where('user_id', $userId)
            ->orderByDesc('requested_at')
            ->limit(10)
            ->get();

        return response()->json(['data' => $rows]);
    }
}
