<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BorrowingController extends Controller
{
    /**
     * GET /v1/me/borrowing
     *
     * Returns all book copies currently borrowed by the authenticated reader
     * (borrow_details.return_date IS NULL).
     *
     * Auth: auth:sanctum middleware — auth()->id() resolves to users.user_id
     * Query: DB::table() only — avoids all dormant Eloquent model bugs
     */
    public function index(Request $request)
    {
        $userId = auth()->id();

        $rows = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bt.user_id', $userId)
            ->whereNull('bd.return_date')
            ->select([
                'bt.borrow_id',
                'bd.copy_id',
                'b.book_id',
                'b.title',
                'b.cover_image',
                DB::raw("DATE_FORMAT(bt.borrow_date, '%Y-%m-%d') as borrow_date"),
                // renewed_due_date: hạn trả riêng của bản sao này sau khi được gia hạn.
                DB::raw("DATE_FORMAT(COALESCE(bd.renewed_due_date, bt.due_date), '%Y-%m-%d') as due_date"),
                DB::raw('DATEDIFF(COALESCE(bd.renewed_due_date, bt.due_date), CURDATE()) as days_remaining'),
            ])
            ->orderBy(DB::raw('COALESCE(bd.renewed_due_date, bt.due_date)'), 'asc')
            ->get();

        // Lấy đúng copy_id nào đang có pending renewal request (không phải cả borrow_id).
        // copy_id NULL = request cũ trước khi có cột này -> fallback áp dụng cho cả giao dịch.
        $pendingRequests = DB::table('borrow_renewal_requests')
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->select('borrow_id', 'copy_id')
            ->get();

        $pendingCopyIds        = $pendingRequests->whereNotNull('copy_id')->pluck('copy_id')->all();
        $pendingBorrowIdsLegacy = $pendingRequests->whereNull('copy_id')->pluck('borrow_id')->all();

        $data = $rows->map(function ($row) use ($pendingCopyIds, $pendingBorrowIdsLegacy) {
            $days = (int) $row->days_remaining;

            if ($days > 3) {
                $color = 'green';
            } elseif ($days >= 1) {
                $color = 'yellow';
            } else {
                $color = 'red';
            }

            return [
                'borrow_id'        => $row->borrow_id,
                'copy_id'          => $row->copy_id,
                'book_id'          => $row->book_id,
                'title'            => $row->title,
                'cover_image'      => $row->cover_image,
                'borrow_date'      => $row->borrow_date,
                'due_date'         => $row->due_date,
                'days_remaining'   => $days,
                'warning_color'    => $color,
                'renewal_pending'  => in_array($row->copy_id, $pendingCopyIds)
                    || in_array($row->borrow_id, $pendingBorrowIdsLegacy),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /v1/me/borrowing/history
     *
     * Returns all returned borrow records for the authenticated reader
     * (borrow_details.return_date IS NOT NULL).
     */
    public function history(Request $request)
    {
        $userId = auth()->id();

        $rows = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bt.user_id', $userId)
            ->whereNotNull('bd.return_date')
            ->select([
                'bt.borrow_id',
                'bd.copy_id',
                'b.book_id',
                'b.title',
                'b.cover_image',
                DB::raw("DATE_FORMAT(bt.borrow_date, '%Y-%m-%d') as borrow_date"),
                DB::raw("DATE_FORMAT(COALESCE(bd.renewed_due_date, bt.due_date), '%Y-%m-%d') as due_date"),
                DB::raw("DATE_FORMAT(bd.return_date, '%Y-%m-%d') as return_date"),
                'bd.renew_count',
                DB::raw("GREATEST(0, DATEDIFF(bd.return_date, COALESCE(bd.renewed_due_date, bt.due_date))) as days_late"),
            ])
            ->orderByDesc('bt.borrow_date')
            ->get();

        $data = $rows->map(function ($row) {
            $daysLate = (int) $row->days_late;
            return [
                'borrow_id'     => $row->borrow_id,
                'copy_id'       => $row->copy_id,
                'book_id'       => $row->book_id,
                'title'         => $row->title,
                'cover_image'   => $row->cover_image,
                'borrow_date'   => $row->borrow_date,
                'due_date'      => $row->due_date,
                'return_date'   => $row->return_date,
                'renew_count'   => (int) $row->renew_count,
                'days_late'     => $daysLate,
                'return_status' => $daysLate === 0
                    ? ['value' => 'on_time', 'label' => 'Đúng hạn']
                    : ['value' => 'late',    'label' => 'Trễ hạn'],
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * POST /v1/me/borrowing/{borrowId}/renew
     *
     * M4.2: Reader gửi yêu cầu gia hạn sách (pending) cho đúng 1 bản sao.
     * Admin sẽ duyệt qua POST /private/v1/checkout/renew.
     *
     * Validation:
     *  - Giao dịch thuộc về user
     *  - copy_id thuộc đúng giao dịch và chưa trả
     *  - Chưa quá hạn
     *  - Còn lượt gia hạn (max_renew_times từ system_settings), tính theo đúng bản sao
     *  - Không có người khác đặt trước
     *  - Chưa có pending request cho đúng bản sao này
     */
    public function renew(Request $request, int $borrowId)
    {
        $userId = auth()->id();

        $validated = $request->validate([
            'copy_id' => ['required', 'integer', 'min:1'],
        ]);
        $copyId = (int) $validated['copy_id'];

        // 1. Verify giao dịch thuộc về user
        $transaction = DB::table('borrow_transactions')
            ->where('borrow_id', $borrowId)
            ->where('user_id', $userId)
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'Không tìm thấy giao dịch mượn.'], 404);
        }

        // 2. Bản sao thuộc đúng giao dịch và chưa trả — chỉ xử lý đúng bản ghi được yêu cầu.
        $detail = DB::table('borrow_details')
            ->where('borrow_id', $borrowId)
            ->where('copy_id', $copyId)
            ->whereNull('return_date')
            ->first();

        if (!$detail) {
            return response()->json(['message' => 'Không tìm thấy sách đang mượn để gia hạn.'], 422);
        }

        // 3. Không gia hạn nếu đã quá hạn (dùng hạn trả hiệu lực của đúng bản sao này)
        $effectiveDueDate = $detail->renewed_due_date ?? $transaction->due_date;
        if (date('Y-m-d') > $effectiveDueDate) {
            return response()->json(['message' => 'Không thể gia hạn sách đã quá hạn.'], 422);
        }

        // 4. Kiểm tra giới hạn gia hạn của đúng bản sao này
        $maxRenewTimes = (int) DB::table('system_settings')
            ->where('config_key', 'max_renew_times')
            ->value('config_value') ?: 2;

        if ((int) $detail->renew_count >= $maxRenewTimes) {
            return response()->json(['message' => 'Bạn đã sử dụng hết số lần gia hạn.'], 422);
        }

        // 5. Kiểm tra reservation của người khác — chỉ cho đúng sách (book_id) của bản sao này
        $bookId = DB::table('book_copies')->where('copy_id', $copyId)->value('book_id');

        $hasReservation = DB::table('reservations')
            ->where('book_id', $bookId)
            ->whereIn('status', ['pending', 'ready_for_pickup'])
            ->where('user_id', '<>', $userId)
            ->exists();

        if ($hasReservation) {
            return response()->json(['message' => 'Sách hiện đã có độc giả khác đặt trước.'], 422);
        }

        // 6. Kiểm tra đã có pending request cho đúng bản sao này chưa
        $hasPending = DB::table('borrow_renewal_requests')
            ->where('borrow_id', $borrowId)
            ->where('copy_id', $copyId)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->exists();

        if ($hasPending) {
            return response()->json(['message' => 'Yêu cầu gia hạn đang chờ thư viện duyệt.'], 422);
        }

        // 7. Tạo pending renewal request — chỉ cho đúng cuốn sách được chọn.
        $requestId = DB::table('borrow_renewal_requests')->insertGetId([
            'borrow_id'    => $borrowId,
            'copy_id'      => $copyId,
            'user_id'      => $userId,
            'status'       => 'pending',
            'requested_at' => now(),
        ]);

        return response()->json([
            'message'    => 'Yêu cầu gia hạn đã được gửi, đang chờ thư viện duyệt.',
            'request_id' => $requestId,
            'pending'    => true,
        ], 201);
    }

    /**
     * DELETE /v1/me/borrowing/{borrowId}/renew
     *
     * Reader tự hủy yêu cầu gia hạn sách. Chỉ cho phép hủy ở trạng thái Pending.
     * Chỉ hủy đúng yêu cầu của bản sao được chọn (copy_id).
     * lockForUpdate + re-check status trong transaction để tránh race với
     * Admin duyệt/từ chối cùng lúc — chỉ một thao tác được thắng.
     */
    public function cancelRenewal(Request $request, int $borrowId)
    {
        $userId = auth()->id();

        $validated = $request->validate([
            'copy_id' => ['required', 'integer', 'min:1'],
        ]);
        $copyId = (int) $validated['copy_id'];

        try {
            DB::transaction(function () use ($borrowId, $copyId, $userId) {
                $renewRequest = DB::table('borrow_renewal_requests')
                    ->where('borrow_id', $borrowId)
                    ->where('user_id', $userId)
                    ->where(function ($q) use ($copyId) {
                        $q->where('copy_id', $copyId)->orWhereNull('copy_id');
                    })
                    ->orderByDesc('request_id')
                    ->lockForUpdate()
                    ->first();

                if (!$renewRequest) {
                    throw new \RuntimeException('NOT_FOUND');
                }

                if ($renewRequest->status !== 'pending') {
                    throw new \RuntimeException('NOT_PENDING');
                }

                DB::table('borrow_renewal_requests')
                    ->where('request_id', $renewRequest->request_id)
                    ->where('status', 'pending')
                    ->update(['status' => 'cancelled']);

                DB::table('notifications')->insert([
                    'user_id'    => $userId,
                    'title'      => 'Đã hủy yêu cầu gia hạn sách',
                    'content'    => 'Bạn đã hủy yêu cầu gia hạn sách.',
                    'type'       => 'borrow_renewal',
                    'is_read'    => 0,
                    'created_at' => now(),
                ]);
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'NOT_FOUND') {
                return response()->json(['message' => 'Không tìm thấy yêu cầu gia hạn.'], 404);
            }

            return response()->json(['message' => 'Yêu cầu đã được xử lý, không thể hủy.'], 422);
        }

        return response()->json(['message' => 'Đã hủy yêu cầu gia hạn sách.']);
    }
}
