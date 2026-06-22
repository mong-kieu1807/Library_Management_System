<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
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
}
