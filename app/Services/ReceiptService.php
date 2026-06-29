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
        $pdf->setOptions($this->dompdfOptions());

        $title = 'Phiếu mượn #' . str_pad($borrowId, 6, '0', STR_PAD_LEFT);
        return $this->wrapPdfAsHtml($pdf->output(), $title);
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
        $due              = Carbon::parse($borrow->due_date)->startOfDay();
        $latestReturnDate = null; // giữ là Carbon instance để tránh parse lại chuỗi d/m/Y

        $returnedBooks->each(function ($book) use ($due, &$latestReturnDate) {
            $retDate            = Carbon::parse($book->return_date)->startOfDay();
            $book->overdue_days = $retDate->gt($due) ? (int) $retDate->diffInDays($due, true) : 0;
            $book->fine_amount  = (int) ($book->fine_amount ?? 0);
            if (!$latestReturnDate || $retDate->gt($latestReturnDate)) {
                $latestReturnDate = $retDate;
            }
        });

        $returnDate = $latestReturnDate ? $latestReturnDate->format('d/m/Y') : today()->format('d/m/Y');

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
            'returnDate'    => $returnDate,
            'totalFine'     => $totalFine,
            'paidAmount'    => $paidAmount,
            'unpaidAmount'  => $unpaidAmount,
            'librarian'     => $borrow->librarian_name ?? 'Thủ thư',
            'libraryName'   => $settings['library_name'] ?? 'Thư Viện',
            'settings'      => $settings,
        ];

        $pdf = Pdf::loadView('receipts.return', $data);
        $pdf->setPaper('A4', 'portrait');
        $pdf->setOptions($this->dompdfOptions());

        $title = 'Biên lai trả sách #' . str_pad($borrowId, 6, '0', STR_PAD_LEFT);
        return $this->wrapPdfAsHtml($pdf->output(), $title);
    }

    /**
     * DomPDF options — trỏ đúng fontDir và fontCache để load DejaVu Sans hỗ trợ tiếng Việt.
     */
    private function dompdfOptions(): array
    {
        $fontDir = base_path('vendor/dompdf/dompdf/lib/fonts');

        return [
            'fontDir'         => $fontDir,
            'fontCache'       => storage_path('fonts'),
            'defaultFont'     => 'DejaVu Sans',
            'isRemoteEnabled' => false,
        ];
    }

    /**
     * Bọc nội dung PDF trong một trang HTML trả về Content-Type: text/html.
     * Kỹ thuật này bypass IDM (Internet Download Manager) vì IDM chỉ chặn
     * response có Content-Type: application/pdf, không chặn text/html.
     * JavaScript trong trang tự tạo blob URL từ base64 và nhúng vào <embed>.
     */
    private function wrapPdfAsHtml(string $pdfBinary, string $title): \Illuminate\Http\Response
    {
        $base64    = base64_encode($pdfBinary);
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        $html = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>{$safeTitle}</title>
  <style>
    * { margin: 0; padding: 0; }
    html, body { width: 100%; height: 100%; overflow: hidden; background: #525659; }
    iframe { display: block; width: 100%; height: 100%; border: none; }
  </style>
</head>
<body>
  <iframe id="v"></iframe>
  <script>
    (function () {
      var b64 = "{$base64}";
      var raw = atob(b64);
      var arr = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
      var blob = new Blob([arr], { type: "application/pdf" });
      document.getElementById("v").src = URL.createObjectURL(blob);
    })();
  </script>
</body>
</html>
HTML;

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
