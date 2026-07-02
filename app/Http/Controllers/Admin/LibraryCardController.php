<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

class LibraryCardController extends Controller
{
    /**
     * GET /private/v1/library-card-renewal
     *
     * Danh sách yêu cầu gia hạn thẻ (mặc định pending).
     */
    public function listRequests(Request $request)
    {
        $status = $request->query('status', 'pending');

        $rows = DB::table('card_renewal_requests as cr')
            ->join('users as u', 'u.user_id', '=', 'cr.user_id')
            ->join('library_cards as lc', 'lc.card_id', '=', 'cr.card_id')
            ->leftJoin('users as rv', 'rv.user_id', '=', 'cr.reviewed_by')
            ->where('cr.status', $status)
            ->orderByDesc('cr.requested_at')
            ->select([
                'cr.request_id',
                'cr.card_id',
                'cr.user_id',
                'u.full_name',
                'u.email',
                'lc.card_number',
                'lc.expiry_date as current_expiry_date',
                'cr.requested_expiry_date',
                'cr.status',
                'cr.review_note',
                'cr.requested_at',
                'rv.full_name as reviewed_by_name',
            ])
            ->get();

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /private/v1/library-card-renewal/{id}/approve
     *
     * Admin duyệt yêu cầu gia hạn thẻ:
     * 1. Cập nhật library_cards.expiry_date
     * 2. Đánh dấu request approved
     * 3. Tạo notification cho reader
     */
    public function approve(Request $request, int $id)
    {
        $adminId = auth()->id();

        $renewRequest = DB::table('card_renewal_requests')
            ->where('request_id', $id)
            ->where('status', 'pending')
            ->first();

        if (!$renewRequest) {
            return response()->json(['message' => 'Yêu cầu không tồn tại hoặc đã được xử lý.'], 404);
        }

        $reviewNote = $request->input('review_note');

        DB::transaction(function () use ($renewRequest, $adminId, $reviewNote) {
            // 1. Cập nhật expiry_date trên thẻ thư viện
            DB::table('library_cards')
                ->where('card_id', $renewRequest->card_id)
                ->update(['expiry_date' => $renewRequest->requested_expiry_date]);

            // 2. Cập nhật trạng thái request
            DB::table('card_renewal_requests')
                ->where('request_id', $renewRequest->request_id)
                ->update([
                    'status'      => 'approved',
                    'reviewed_by' => $adminId,
                    'review_note' => $reviewNote,
                ]);

            // 3. Tạo notification cho reader
            DB::table('notifications')->insert([
                'user_id'    => $renewRequest->user_id,
                'title'      => 'Gia hạn thẻ thư viện thành công',
                'content'    => 'Yêu cầu gia hạn thẻ thư viện của bạn đã được duyệt. Ngày hết hạn mới: '
                    . Carbon::parse($renewRequest->requested_expiry_date)->format('d/m/Y') . '.',
                'type'       => 'card_renewal',
                'is_read'    => 0,
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Đã duyệt gia hạn thẻ thư viện thành công.']);
    }

    /**
     * POST /private/v1/library-card-renewal/{id}/reject
     *
     * Admin từ chối yêu cầu gia hạn thẻ.
     */
    public function reject(Request $request, int $id)
    {
        $adminId = auth()->id();

        $renewRequest = DB::table('card_renewal_requests')
            ->where('request_id', $id)
            ->where('status', 'pending')
            ->first();

        if (!$renewRequest) {
            return response()->json(['message' => 'Yêu cầu không tồn tại hoặc đã được xử lý.'], 404);
        }

        $reviewNote = $request->input('review_note', 'Yêu cầu bị từ chối.');

        DB::transaction(function () use ($renewRequest, $adminId, $reviewNote) {
            DB::table('card_renewal_requests')
                ->where('request_id', $renewRequest->request_id)
                ->update([
                    'status'      => 'rejected',
                    'reviewed_by' => $adminId,
                    'review_note' => $reviewNote,
                ]);

            DB::table('notifications')->insert([
                'user_id'    => $renewRequest->user_id,
                'title'      => 'Yêu cầu gia hạn thẻ bị từ chối',
                'content'    => 'Yêu cầu gia hạn thẻ thư viện của bạn đã bị từ chối. Lý do: ' . $reviewNote,
                'type'       => 'card_renewal',
                'is_read'    => 0,
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Đã từ chối yêu cầu gia hạn thẻ.']);
    }
}
