<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReceiptController extends Controller
{
    public function __construct(private readonly ReceiptService $receiptService) {}

    /**
     * GET /private/v1/receipt/checkout/{borrow_id}
     *
     * Tạo PDF phiếu mượn sách. Chỉ admin/librarian được truy cập.
     */
    public function checkoutReceipt(int $borrowId)
    {
        return $this->receiptService->generateCheckoutPdf($borrowId);
    }

    /**
     * GET /private/v1/receipt/return/{borrow_id}
     *
     * Tạo PDF biên lai trả sách. Chỉ admin/librarian được truy cập.
     */
    public function returnReceipt(int $borrowId)
    {
        return $this->receiptService->generateReturnPdf($borrowId);
    }
}
