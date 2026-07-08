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
                // copy_id khớp đúng bản sao được yêu cầu; brr.copy_id NULL (request cũ
                // trước khi có cột này) fallback áp dụng cho cả giao dịch.
                $j->on('bd.borrow_id', '=', 'bt.borrow_id')
                  ->whereNull('bd.return_date')
                  ->where(function ($jj) {
                      $jj->whereColumn('bd.copy_id', '=', 'brr.copy_id')
                         ->orWhereNull('brr.copy_id');
                  });
            })
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->join('users as u', 'u.user_id', '=', 'bt.user_id')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'u.user_id')
            ->where('brr.status', 'pending')
            ->select([
                'brr.request_id',
                'bt.borrow_id', 'bt.user_id', 'bt.borrow_date',
                DB::raw('COALESCE(bd.renewed_due_date, bt.due_date) as due_date'),
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
     * Sau khi gia hạn thành công:
     *   - Tìm borrow_renewal_requests pending tương ứng → mark approved
     *   - Tạo notification cho reader (nếu có pending request)
     *
     * Transaction flow:
     *   1. Đọc max_renew_times từ system_settings (ngoài tx)
     *   2. lockForUpdate borrow_details + borrow_transactions + book_copies
     *   3. Safety validate: ownership, return_date IS NULL, renew_count, reservation
     *   4. INCREMENT borrow_details.renew_count (bulk, 1 query)
     *   5. UPDATE borrow_details.renewed_due_date += extend_days (per copy_id — không
     *      đụng borrow_transactions.due_date, vì cột đó dùng chung cho cả giao dịch
     *      và có thể có nhiều sách khác chưa được duyệt gia hạn)
     *   6. Mark pending borrow_renewal_requests approved (đúng copy_id) + tạo notification
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
                    ->select('bd.copy_id', 'bd.borrow_id', 'bd.renew_count', 'bd.renewed_due_date', 'bt.user_id', 'bt.due_date', 'bc.book_id')
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

                // [4] UPDATE renewed_due_date per bản sao — chỉ đúng copy_id được duyệt,
                // không đụng đến sách khác cùng giao dịch (borrow_id).
                $renewedCopies = [];

                foreach ($details as $detail) {
                    $currentDue = Carbon::parse($detail->renewed_due_date ?? $detail->due_date)->startOfDay();
                    $newDue     = $currentDue->addDays($extendDays);

                    DB::table('borrow_details')
                        ->where('borrow_id', (int) $detail->borrow_id)
                        ->where('copy_id', (int) $detail->copy_id)
                        ->update([
                            'renewed_due_date' => $newDue->toDateString(),
                        ]);

                    $renewedCopies[] = [
                        'borrow_id'    => (int) $detail->borrow_id,
                        'copy_id'      => (int) $detail->copy_id,
                        'new_due_date' => $newDue->toDateString(),
                    ];

                    // [5] Nếu có borrow_renewal_request đang pending khớp đúng bản sao này
                    // (Reader đã gửi yêu cầu gia hạn) → duyệt luôn + tạo notification.
                    // copy_id NULL = request cũ trước khi có cột này -> fallback theo borrow_id.
                    // lockForUpdate + re-check status: tránh race với Reader hủy yêu cầu
                    // cùng lúc admin renew trực tiếp tại quầy.
                    $pendingRequest = DB::table('borrow_renewal_requests')
                        ->where('borrow_id', (int) $detail->borrow_id)
                        ->where('user_id', $userId)
                        ->where('status', 'pending')
                        ->where(function ($q) use ($detail) {
                            $q->where('copy_id', (int) $detail->copy_id)->orWhereNull('copy_id');
                        })
                        ->lockForUpdate()
                        ->first();

                    if ($pendingRequest) {
                        DB::table('borrow_renewal_requests')
                            ->where('request_id', $pendingRequest->request_id)
                            ->where('status', 'pending')
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
                    'extended_books'       => count($copyIds),
                    'extend_days'          => $extendDays,
                    'renewed_transactions' => $renewedCopies,
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
     *
     * lockForUpdate + re-check status BÊN TRONG transaction để tránh race với
     * Reader hủy yêu cầu cùng lúc — chỉ một thao tác được thắng.
     */
    public function rejectBook(Request $request, int $id)
    {
        $adminId    = auth()->id();
        $reviewNote = $request->input('review_note', 'Yêu cầu bị từ chối.');

        try {
            DB::transaction(function () use ($id, $adminId, $reviewNote) {
                $renewRequest = DB::table('borrow_renewal_requests')
                    ->where('request_id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$renewRequest || $renewRequest->status !== 'pending') {
                    throw new \RuntimeException('NOT_PENDING');
                }

                DB::table('borrow_renewal_requests')
                    ->where('request_id', $renewRequest->request_id)
                    ->where('status', 'pending')
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
        } catch (\RuntimeException $e) {
            return response()->json([
                'code'    => 404,
                'message' => 'Yêu cầu không tồn tại hoặc đã được xử lý.',
            ], 404);
        }

        return response()->json([
            'code'    => 200,
            'message' => 'Đã từ chối yêu cầu gia hạn sách.',
        ]);
    }
}
