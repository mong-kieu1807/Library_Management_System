<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Requests\RenewBookRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RenewController extends Controller
{
    /**
     * GET /private/v1/checkout/renew-list
     *
     * Danh sách yêu cầu gia hạn sách ĐANG PENDING (Reader đã gửi qua
     * POST /v1/me/borrowing/{borrowId}/renew) — hiển thị trên tab "Yêu cầu gia hạn".
     * Không phân trang vì số lượng thường nhỏ.
     */
    public function getRenewList()
    {
        $maxRenewTimes = (int) DB::table('system_settings')
            ->where('config_key', 'max_renew_times')->value('config_value') ?: 2;

        // Lấy các book_id đang có reservation waiting
        $reservedBookIds = DB::table('reservations')
            ->whereIn('status', ['waiting', 'ready'])
            ->pluck('book_id')
            ->unique()
            ->toArray();

        $rows = DB::table('borrow_renewal_requests as brr')
            ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'brr.borrow_id')
            ->join('borrow_details as bd', function ($j) {
                $j->on('bd.borrow_id', '=', 'bt.borrow_id')->whereNull('bd.return_date');
            })
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->join('users as u', 'u.user_id', '=', 'bt.user_id')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'u.user_id')
            ->where('brr.status', 'pending')
            ->select([
                'brr.request_id',
                'bt.borrow_id', 'bt.user_id', 'bt.borrow_date', 'bt.due_date',
                'bd.copy_id', 'bd.renew_count',
                'bc.barcode', 'bc.book_id',
                'b.title',
                'u.full_name',
                'lc.card_number',
            ])
            ->orderByDesc('brr.requested_at')
            ->get()
            ->map(function ($row) use ($maxRenewTimes, $reservedBookIds) {
                $atLimit      = (int) $row->renew_count >= $maxRenewTimes;
                $hasReserve   = in_array($row->book_id, $reservedBookIds);
                $canRenew     = !$atLimit && !$hasReserve;
                $denyReason   = $atLimit
                    ? "Đã đạt giới hạn ({$maxRenewTimes} lần)"
                    : ($hasReserve ? 'Sách đang được đặt trước' : null);

                return [
                    'request_id'      => $row->request_id,
                    'borrow_id'       => $row->borrow_id,
                    'user_id'         => $row->user_id,
                    'full_name'       => $row->full_name,
                    'card_number'     => $row->card_number,
                    'copy_id'         => $row->copy_id,
                    'barcode'         => $row->barcode,
                    'book_id'         => $row->book_id,
                    'title'           => $row->title,
                    'borrow_date'     => $row->borrow_date,
                    'due_date'        => $row->due_date,
                    'renew_count'     => (int) $row->renew_count,
                    'max_renew_times' => $maxRenewTimes,
                    'can_renew'       => $canRenew,
                    'deny_reason'     => $denyReason,
                ];
            });

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $rows, 'meta' => ['max_renew_times' => $maxRenewTimes]],
        ]);
    }

    /**
     * POST /private/v1/checkout/renew
     *
     * Gia hạn ngày trả sách.
     *
     * Transaction flow:
     *   1. Đọc max_renew_times từ system_settings (ngoài tx)
     *   2. lockForUpdate borrow_details + borrow_transactions + book_copies
     *   3. Safety validate: ownership, return_date IS NULL, renew_count, reservation
     *   4. INCREMENT borrow_details.renew_count (bulk, 1 query)
     *   5. UPDATE borrow_transactions.due_date += extend_days (per borrow_id)
     */
    public function renewBook(RenewBookRequest $request)
    {
        // [0] Config — ngoài transaction
        $maxRenewTimes = (int) DB::table('system_settings')
            ->where('config_key', 'max_renew_times')
            ->value('config_value') ?: 2;

        $adminId    = auth()->id();
        $userId     = (int) $request->input('user_id');
        $copyIds    = array_values(array_unique(array_map('intval', $request->input('copy_ids', []))));
        $extendDays = (int) $request->input('extend_days');

        try {
            $result = DB::transaction(function () use ($userId, $copyIds, $extendDays, $maxRenewTimes, $adminId) {
                // [1] LOCK — borrow_details + borrow_transactions + book_copies
                $details = DB::table('borrow_details as bd')
                    ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
                    ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
                    ->whereIn('bd.copy_id', $copyIds)
                    ->whereNull('bd.return_date')
                    ->select('bd.copy_id', 'bd.borrow_id', 'bd.renew_count', 'bt.user_id', 'bt.due_date', 'bc.book_id')
                    ->lockForUpdate()
                    ->get();

                // [2] SAFETY VALIDATE
                $lockedIds = $details->pluck('copy_id')->toArray();
                if (!empty(array_diff($copyIds, $lockedIds))) {
                    throw new \RuntimeException('INVALID:some copies not active or not found');
                }
                if ($details->contains(fn($d) => (int) $d->user_id !== $userId)) {
                    throw new \RuntimeException('INVALID:ownership mismatch');
                }

                // Kiểm tra renew limit
                $overLimit = $details->filter(fn($d) => (int) $d->renew_count >= $maxRenewTimes);
                if ($overLimit->isNotEmpty()) {
                    throw new \RuntimeException('RENEW_LIMIT:exceeded');
                }

                // Kiểm tra reservation — sách đang được đặt trước bởi người khác
                $bookIds = $details->pluck('book_id')->unique()->toArray();
                $hasReservation = DB::table('reservations')
                    ->whereIn('book_id', $bookIds)
                    ->where('status', 'waiting')
                    ->exists();
                if ($hasReservation) {
                    throw new \RuntimeException('RESERVATION:active reservation');
                }

                // [3] INCREMENT renew_count (bulk, 1 query)
                DB::table('borrow_details')
                    ->whereIn('copy_id', $copyIds)
                    ->whereNull('return_date')
                    ->increment('renew_count');

                // [4] UPDATE due_date per unique borrow_transaction
                $borrowGroups       = $details->groupBy('borrow_id');
                $renewedTransactions = [];

                foreach ($borrowGroups as $borrowId => $group) {
                    $currentDue = Carbon::parse($group->first()->due_date)->startOfDay();
                    $newDue     = $currentDue->addDays($extendDays);

                    DB::table('borrow_transactions')
                        ->where('borrow_id', (int) $borrowId)
                        ->update([
                            'due_date'   => $newDue->toDateString(),
                            'updated_at' => now(),
                        ]);

                    $renewedTransactions[] = [
                        'borrow_id'    => (int) $borrowId,
                        'new_due_date' => $newDue->toDateString(),
                    ];

                    // [5] Nếu có borrow_renewal_request đang pending khớp giao dịch này
                    // (Reader đã gửi yêu cầu gia hạn) → duyệt luôn + tạo notification.
                    $pendingRequest = DB::table('borrow_renewal_requests')
                        ->where('borrow_id', (int) $borrowId)
                        ->where('user_id', $userId)
                        ->where('status', 'pending')
                        ->first();

                    if ($pendingRequest) {
                        DB::table('borrow_renewal_requests')
                            ->where('request_id', $pendingRequest->request_id)
                            ->update([
                                'status'      => 'approved',
                                'reviewed_by' => $adminId,
                                'review_note' => null,
                            ]);

                        DB::table('notifications')->insert([
                            'user_id'    => $userId,
                            'title'      => 'Gia hạn sách thành công',
                            'content'    => 'Yêu cầu gia hạn sách của bạn đã được duyệt. Hạn trả mới: '
                                . $newDue->format('d/m/Y') . '.',
                            'type'       => 'borrow_renewal',
                            'is_read'    => 0,
                            'created_at' => now(),
                        ]);
                    }
                }

                return [
                    'extended_books'        => count($copyIds),
                    'extend_days'           => $extendDays,
                    'renewed_transactions'  => $renewedTransactions,
                ];
            });

            return response()->json([
                'code'    => 200,
                'message' => 'Gia hạn thành công.',
                'results' => ['object' => $result],
            ]);

        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'INVALID:')) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'Một hoặc nhiều bản sao không hợp lệ hoặc đã được trả.',
                ], 422);
            }
            if (str_starts_with($msg, 'RENEW_LIMIT:')) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'Một hoặc nhiều sách đã đạt giới hạn gia hạn (' . $maxRenewTimes . ' lần).',
                ], 422);
            }
            if (str_starts_with($msg, 'RESERVATION:')) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'Không thể gia hạn: sách đang được đặt trước bởi độc giả khác.',
                ], 422);
            }
            throw $e;
        }
    }

    /**
     * POST /private/v1/checkout/renew/{id}/reject
     *
     * Admin từ chối yêu cầu gia hạn sách (id = borrow_renewal_requests.request_id).
     * KHÔNG đụng due_date / renew_count — chỉ cập nhật request + tạo notification.
     */
    public function rejectBook(Request $request, int $id)
    {
        $adminId = auth()->id();

        $renewRequest = DB::table('borrow_renewal_requests')
            ->where('request_id', $id)
            ->where('status', 'pending')
            ->first();

        if (!$renewRequest) {
            return response()->json([
                'code'    => 404,
                'message' => 'Yêu cầu không tồn tại hoặc đã được xử lý.',
            ], 404);
        }

        $reviewNote = $request->input('review_note', 'Yêu cầu bị từ chối.');

        DB::transaction(function () use ($renewRequest, $adminId, $reviewNote) {
            DB::table('borrow_renewal_requests')
                ->where('request_id', $renewRequest->request_id)
                ->update([
                    'status'      => 'rejected',
                    'reviewed_by' => $adminId,
                    'review_note' => $reviewNote,
                ]);

            DB::table('notifications')->insert([
                'user_id'    => $renewRequest->user_id,
                'title'      => 'Yêu cầu gia hạn sách bị từ chối',
                'content'    => 'Yêu cầu gia hạn sách của bạn đã bị từ chối. Lý do: ' . $reviewNote,
                'type'       => 'borrow_renewal',
                'is_read'    => 0,
                'created_at' => now(),
            ]);
        });

        return response()->json([
            'code'    => 200,
            'message' => 'Đã từ chối yêu cầu gia hạn sách.',
        ]);
    }
}
