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
            ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'f.borrow_id')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
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
                DB::raw("GREATEST(0, DATEDIFF(COALESCE(bd.return_date, CURDATE()), bt.due_date)) as days_late"),
                'f.amount',
                'f.reason',
                'f.status',
                DB::raw("DATE_FORMAT(f.created_at, '%Y-%m-%d') as created_at"),
            ])
            ->orderByDesc('f.fine_id')
            ->get();

        $data = $rows->map(function ($row) {
            return [
                'fine_id'     => $row->fine_id,
                'borrow_id'   => $row->borrow_id,
                'book_id'     => $row->book_id,
                'title'       => $row->title,
                'cover_image' => $row->cover_image,
                'borrow_date' => $row->borrow_date,
                'due_date'    => $row->due_date,
                'return_date' => $row->return_date,
                'days_late'   => (int)   $row->days_late,
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
