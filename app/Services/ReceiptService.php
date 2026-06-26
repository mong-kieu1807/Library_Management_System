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
        // [1] Borrow + reader + librarian
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

        // [2] Books — barcode + title only (isbn removed)
        $books = DB::table('borrow_details as bd')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bd.borrow_id', $borrowId)
            ->select(['bc.barcode', 'b.title'])
            ->get();

        // [3] Settings
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['library_name', 'address', 'contact_phone'])
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
     * JOIN payments để tính paid_amount và unpaid_amount.
     */
    public function generateReturnPdf(int $borrowId): \Illuminate\Http\Response
    {
        // [1] Borrow + reader + librarian
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

        // [2] Returned books — barcode + title + overdue_days (no per-copy fee column)
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
                DB::raw('COALESCE(f.amount, 0) AS fine_amount'),
                DB::raw('f.fine_id'),
            ])
            ->get();

        // [3] Overdue days per book + latest return date
        $due        = Carbon::parse($borrow->due_date)->startOfDay();
        $returnDate = null;

        $returnedBooks->each(function ($book) use ($due, &$returnDate) {
            $retDate             = Carbon::parse($book->return_date)->startOfDay();
            $book->overdue_days  = $retDate->gt($due) ? (int) $retDate->diffInDays($due, true) : 0;
            $book->fine_amount   = (int) ($book->fine_amount ?? 0);
            if (!$returnDate || $retDate->gt(Carbon::parse($returnDate))) {
                $returnDate = $retDate->format('d/m/Y');
            }
        });

        // [4] Fine summary — total from fines table
        $totalFine = (int) $returnedBooks->sum('fine_amount');

        // [5] Paid amount — SUM of payments for fines of this borrow
        $fineIds = $returnedBooks->filter(fn($b) => $b->fine_id)->pluck('fine_id')->toArray();

        $paidAmount = 0;
        if (!empty($fineIds)) {
            $paidAmount = (int) DB::table('payments')
                ->whereIn('fine_id', $fineIds)
                ->sum('amount');
        }

        $unpaidAmount = max(0, $totalFine - $paidAmount);

        // [6] Settings
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['library_name', 'address', 'contact_phone'])
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
            'totalFine'     => $totalFine,
            'paidAmount'    => $paidAmount,
            'unpaidAmount'  => $unpaidAmount,
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
