<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FineController extends Controller
{
    /**
     * GET /v1/me/fines
     *
     * Returns all fines belonging to the authenticated reader.
     * Uses DB::table() only — Fine model is not in sync with DB schema.
     */
    public function index(Request $request)
    {
        $userId = auth()->id();

        $rows = DB::table('fines as f')
            ->leftJoin('borrow_transactions as bt', 'bt.borrow_id', '=', 'f.borrow_id')
            ->leftJoin('borrow_details as bd', function ($join) {
                $join->on('bd.borrow_id', '=', 'f.borrow_id')
                     ->on('bd.copy_id', '=', 'f.copy_id');
            })
            ->leftJoin('book_copies as bc', 'bc.copy_id', '=', 'f.copy_id')
            ->leftJoin('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('f.user_id', $userId)
            ->select([
                'f.fine_id',
                'f.borrow_id',
                'b.book_id',
                'b.title',
                'b.cover_image',
                DB::raw("DATE_FORMAT(bt.borrow_date, '%Y-%m-%d') as borrow_date"),
                DB::raw("DATE_FORMAT(bt.due_date,    '%Y-%m-%d') as due_date"),
                DB::raw("DATE_FORMAT(bd.return_date, '%Y-%m-%d') as return_date"),
                'f.amount',
                'f.reason',
                'f.status',
                DB::raw("DATE_FORMAT(f.created_at, '%Y-%m-%d') as created_at"),
            ])
            ->orderByDesc('f.fine_id')
            ->get();

        // Lấy tất cả ngày nghỉ để loại trừ
        $holidays = DB::table('holidays')->pluck('holiday_date')->toArray();

        $data = $rows->map(function ($row) use ($holidays) {
            $daysLate = 0;
            if ($row->due_date) {
                $due = \Carbon\Carbon::parse($row->due_date)->startOfDay();
                $end = $row->return_date
                    ? \Carbon\Carbon::parse($row->return_date)->startOfDay()
                    : \Carbon\Carbon::today();

                if ($end->gt($due)) {
                    $overdueDays = (int) $end->diffInDays($due, true);
                    $dueStr = $due->toDateString();
                    $endStr = $end->toDateString();
                    $holidayCount = 0;
                    foreach ($holidays as $hDate) {
                        if ($hDate > $dueStr && $hDate <= $endStr) {
                            $holidayCount++;
                        }
                    }
                    $daysLate = max(0, $overdueDays - $holidayCount);
                }
            }

            return [
                'fine_id'     => $row->fine_id,
                'borrow_id'   => $row->borrow_id,
                'book_id'     => $row->book_id,
                'title'       => $row->title ?? '—',
                'cover_image' => $row->cover_image,
                'borrow_date' => $row->borrow_date,
                'due_date'    => $row->due_date,
                'return_date' => $row->return_date,
                'days_late'   => $daysLate,
                'amount'      => (float) $row->amount,
                'reason'      => $row->reason,
                'status'      => $row->status === 'paid'
                    ? ['value' => 'paid',   'label' => 'Đã nộp']
                    : ['value' => 'unpaid', 'label' => 'Đang nợ'],
                'created_at'  => $row->created_at,
            ];
        });

        return response()->json(['data' => $data]);
    }
}
