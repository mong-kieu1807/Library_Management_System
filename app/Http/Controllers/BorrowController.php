<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class BorrowController extends Controller
{
    public function currentBorrowings($userId)
    {
        $borrowings = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bt.borrow_id', '=', 'bd.borrow_id')
            ->join('book_copies as bc', 'bd.copy_id', '=', 'bc.copy_id')
            ->join('books as b', 'bc.book_id', '=', 'b.book_id')
            ->where('bt.user_id', $userId)
            ->where('bt.status', 'borrowing')
            ->select(
                'bt.borrow_id',
                'b.title',
                'bt.borrow_date',
                'bt.due_date'
            )
            ->get();

        return response()->json($borrowings);
    }
}