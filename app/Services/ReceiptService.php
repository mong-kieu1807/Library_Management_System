<?php

namespace App\Services;

use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class ReceiptService
{
    /**
     * Tạo PDF phiếu mượn sách cho một borrow_transaction.
     */
    public function generateCheckoutPdf(int $borrowId): \Illuminate\Http\Response
    {
        // [1] Borrow + reader
        $borrow = DB::table('borrow_transactions as bt')
            ->join('users as u', 'u.user_id', '=', 'bt.user_id')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'u.user_id')
            ->leftJoin('users as lib', 'lib.user_id', '=', 'bt.librarian_id')
            ->where('bt.borrow_id', $borrowId)
            ->select([
                'bt.borrow_id', 'bt.borrow_date', 'bt.due_date', 'bt.status',
                'u.full_name', 'u.email',
                'lc.card_number',
                'lib.full_name as librarian_name',
            ])
            ->first();

        abort_if(!$borrow, 404, 'Phiếu mượn không tồn tại.');

        // [2] Books
        $books = DB::table('borrow_details as bd')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bd.borrow_id', $borrowId)
            ->select(['bc.barcode', 'b.title', 'b.isbn'])
            ->get();

        // [3] Settings
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['library_name', 'address', 'contact_phone', 'fine_per_day'])
            ->pluck('config_value', 'config_key');

        $data = [
            'borrow'      => $borrow,
            'reader'      => (object) [
                'full_name'   => $borrow->full_name,
                'email'       => $borrow->email,
                'card_number' => $borrow->card_number,
            ],
            'books'       => $books,
            'librarian'   => $borrow->librarian_name ?? 'Thủ thư',
            'libraryName' => $settings['library_name'] ?? 'Thư Viện',
            'finePerDay'  => (int) ($settings['fine_per_day'] ?? 5000),
            'settings'    => $settings,
        ];

        $pdf = Pdf::loadView('receipts.checkout', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'phieu-muon-' . str_pad($borrowId, 6, '0', STR_PAD_LEFT) . '.pdf';
        return $pdf->stream($filename);
    }

    /**
     * Tạo PDF biên lai trả sách cho một borrow_transaction.
     * Hiển thị tất cả sách đã trả (return_date IS NOT NULL).
     */
    public function generateReturnPdf(int $borrowId): \Illuminate\Http\Response
    {
        // [1] Borrow + reader
        $borrow = DB::table('borrow_transactions as bt')
            ->join('users as u', 'u.user_id', '=', 'bt.user_id')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'u.user_id')
            ->leftJoin('users as lib', 'lib.user_id', '=', 'bt.librarian_id')
            ->where('bt.borrow_id', $borrowId)
            ->select([
                'bt.borrow_id', 'bt.borrow_date', 'bt.due_date', 'bt.status',
                'u.full_name', 'u.email',
                'lc.card_number',
                'lib.full_name as librarian_name',
            ])
            ->first();

        abort_if(!$borrow, 404, 'Phiếu mượn không tồn tại.');

        // [2] Returned books with fine info
        $returnedBooks = DB::table('borrow_details as bd')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('fines as f', function ($join) {
                $join->on('f.borrow_id', '=', 'bd.borrow_id')
                     ->on('f.copy_id', '=', 'bd.copy_id');
            })
            ->where('bd.borrow_id', $borrowId)
            ->whereNotNull('bd.return_date')
            ->select([
                'bc.barcode',
                'b.title',
                'bd.return_date',
                'bd.condition_return',
                DB::raw('f.amount AS fine_amount'),
                DB::raw('f.status AS fine_status'),
            ])
            ->get();

        // [3] Overdue days per book (PHP-layer: Carbon)
        $due    = Carbon::parse($borrow->due_date)->startOfDay();
        $today  = Carbon::today();
        $returnDate = null;

        $returnedBooks->each(function ($book) use ($due, &$returnDate) {
            $retDate        = Carbon::parse($book->return_date)->startOfDay();
            $overdueDays    = $retDate->gt($due) ? (int) $retDate->diffInDays($due, true) : 0;
            $book->overdue_days = $overdueDays;
            $book->fine_amount  = (int) ($book->fine_amount ?? 0);
            if (!$returnDate || $retDate->gt(Carbon::parse($returnDate))) {
                $returnDate = $retDate->format('d/m/Y');
            }
        });

        $totalPenalty = $returnedBooks->sum('fine_amount');

        // [4] Settings
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['library_name', 'address', 'contact_phone', 'fine_per_day'])
            ->pluck('config_value', 'config_key');

        $data = [
            'borrow'        => $borrow,
            'reader'        => (object) [
                'full_name'   => $borrow->full_name,
                'email'       => $borrow->email,
                'card_number' => $borrow->card_number,
            ],
            'returnedBooks' => $returnedBooks,
            'returnDate'    => $returnDate ?? today()->format('d/m/Y'),
            'totalPenalty'  => $totalPenalty,
            'librarian'     => $borrow->librarian_name ?? 'Thủ thư',
            'libraryName'   => $settings['library_name'] ?? 'Thư Viện',
            'settings'      => $settings,
        ];

        $pdf = Pdf::loadView('receipts.return', $data);
        $pdf->setPaper('A4', 'portrait');

        $filename = 'bien-lai-tra-' . str_pad($borrowId, 6, '0', STR_PAD_LEFT) . '.pdf';
        return $pdf->stream($filename);
    }
}
