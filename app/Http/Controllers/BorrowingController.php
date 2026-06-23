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
                DB::raw("DATE_FORMAT(bt.due_date, '%Y-%m-%d') as due_date"),
                DB::raw('DATEDIFF(bt.due_date, CURDATE()) as days_remaining'),
            ])
            ->orderBy('bt.due_date', 'asc')
            ->get();

        $data = $rows->map(function ($row) {
            $days = (int) $row->days_remaining;

            if ($days > 3) {
                $color = 'green';
            } elseif ($days >= 1) {
                $color = 'yellow';
            } else {
                $color = 'red';
            }

            return [
                'borrow_id'      => $row->borrow_id,
                'copy_id'        => $row->copy_id,
                'book_id'        => $row->book_id,
                'title'          => $row->title,
                'cover_image'    => $row->cover_image,
                'borrow_date'    => $row->borrow_date,
                'due_date'       => $row->due_date,
                'days_remaining' => $days,
                'warning_color'  => $color,
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
                DB::raw("DATE_FORMAT(bt.due_date,    '%Y-%m-%d') as due_date"),
                DB::raw("DATE_FORMAT(bd.return_date, '%Y-%m-%d') as return_date"),
                'bt.renew_count',
                DB::raw("GREATEST(0, DATEDIFF(bd.return_date, bt.due_date)) as days_late"),
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
     * Extends due_date by 7 days. Max 2 renewals per transaction.
     * Blocked when: overdue, max renewals reached, other reader has active reservation.
     */
    public function renew(Request $request, int $borrowId)
    {
        $userId = auth()->id();

        // 1. Verify the transaction belongs to this user
        $transaction = DB::table('borrow_transactions')
            ->where('borrow_id', $borrowId)
            ->where('user_id', $userId)
            ->first();

        if (!$transaction) {
            return response()->json(['message' => 'Không tìm thấy giao dịch mượn.'], 404);
        }

        // 2. Verify at least one unreturned copy exists
        $hasUnreturned = DB::table('borrow_details')
            ->where('borrow_id', $borrowId)
            ->whereNull('return_date')
            ->exists();

        if (!$hasUnreturned) {
            return response()->json(['message' => 'Giao dịch này không còn sách đang mượn để gia hạn.'], 422);
        }

        // 3. Reject if already overdue
        if (date('Y-m-d') > $transaction->due_date) {
            return response()->json(['message' => 'Không thể gia hạn sách đã quá hạn.'], 422);
        }

        // 4. Reject if renewal limit reached
        if ((int) $transaction->renew_count >= 2) {
            return response()->json(['message' => 'Bạn đã sử dụng hết số lần gia hạn.'], 422);
        }

        // 5. Reject if another reader has an active reservation for any book in this transaction
        $bookIds = DB::table('borrow_details as bd')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->where('bd.borrow_id', $borrowId)
            ->whereNull('bd.return_date')
            ->pluck('bc.book_id');

        $hasReservation = DB::table('reservations')
            ->whereIn('book_id', $bookIds)
            ->whereIn('status', ['waiting', 'ready'])
            ->where('user_id', '<>', $userId)
            ->exists();

        if ($hasReservation) {
            return response()->json(['message' => 'Sách hiện đã có độc giả khác đặt trước.'], 422);
        }

        // 6. Compute new values before the transaction
        $newDueDate    = date('Y-m-d', strtotime($transaction->due_date . ' +7 days'));
        $newRenewCount = (int) $transaction->renew_count + 1;

        // 7. Persist inside a DB transaction
        DB::transaction(function () use ($borrowId, $newDueDate, $newRenewCount) {
            DB::table('borrow_transactions')
                ->where('borrow_id', $borrowId)
                ->update([
                    'due_date'    => $newDueDate,
                    'renew_count' => $newRenewCount,
                    'updated_at'  => now(),
                ]);

            DB::table('borrow_details')
                ->where('borrow_id', $borrowId)
                ->whereNull('return_date')
                ->increment('renew_count');
        });

        return response()->json([
            'results' => [
                'object' => [
                    'borrowId'   => $borrowId,
                    'renewCount' => $newRenewCount,
                    'newDueDate' => $newDueDate,
                ],
            ],
            'message' => 'Gia hạn thành công.',
        ]);
    }
}
