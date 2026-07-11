<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Nguồn sự thật DUY NHẤT cho "số ngày quá hạn" trong toàn hệ thống — dùng chung bởi
 * BorrowingController (trang "Đang mượn"), FineController (trang "Lịch sử phí"),
 * ReturnController (chốt tiền phạt lúc trả sách thật), FineService (chốt nợ trước khi
 * thanh toán) và lệnh artisan fines:sync-unpaid-late.
 *
 * Công thức (đã xác nhận với người dùng, áp dụng thống nhất, không loại trừ ngày nghỉ):
 *   actual_days_late = max(0, DATEDIFF(reference_date, effective_due_date))
 *
 * effective_due_date = COALESCE(borrow_details.renewed_due_date, borrow_transactions.due_date)
 * reference_date = return_date thực tế nếu sách đã trả, ngược lại là ngày hiện tại.
 */
class OverdueCalculator
{
    public static function daysLate(string $effectiveDueDate, ?string $referenceDate = null): int
    {
        $due = Carbon::parse($effectiveDueDate)->startOfDay();
        $end = $referenceDate ? Carbon::parse($referenceDate)->startOfDay() : Carbon::today();

        return $end->gt($due) ? (int) $end->diffInDays($due, true) : 0;
    }
}
