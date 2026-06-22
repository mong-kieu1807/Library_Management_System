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
}
