<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\OverdueCalculator;

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
            // recordPayment() chặn double-pay (status='paid' -> reject) nên 1 fine tối đa
            // 1 payment -> left join an toàn, không cần group by / aggregate.
            ->leftJoin('payments as p', 'p.fine_id', '=', 'f.fine_id')
            ->where('f.user_id', $userId)
            ->select([
                'f.fine_id',
                'f.borrow_id',
                'b.book_id',
                'b.title',
                'b.cover_image',
                DB::raw("DATE_FORMAT(bt.borrow_date, '%Y-%m-%d') as borrow_date"),
                DB::raw("DATE_FORMAT(COALESCE(bd.renewed_due_date, bt.due_date), '%Y-%m-%d') as due_date"),
                DB::raw("DATE_FORMAT(bd.return_date, '%Y-%m-%d') as return_date"),
                DB::raw("DATE_FORMAT(p.payment_date, '%Y-%m-%d') as payment_date"),
                'f.amount',
                'f.reason',
                'f.status',
                DB::raw("DATE_FORMAT(f.created_at, '%Y-%m-%d') as created_at"),
            ])
            ->orderByDesc('f.fine_id')
            ->get();

        // Không loại trừ ngày nghỉ ở đây: công thức số ngày quá hạn cho phí trễ hạn CHƯA
        // thanh toán phải khớp tuyệt đối với BorrowingController::index() ("Đang mượn" —
        // DATEDIFF thuần theo lịch, không trừ ngày nghỉ), tránh duy trì 2 công thức khác
        // nhau cho cùng khái niệm "số ngày quá hạn" của cùng 1 borrow_id/copy_id.
        $finePerDay = (int) DB::table('system_settings')->where('config_key', 'fine_per_day')->value('config_value');

        $data = $rows->map(function ($row) use ($finePerDay) {
            // Phân loại theo từ khóa trong `reason` — CHỈ nhận diện 'late' khi có từ khóa
            // rõ ràng ("trễ"/"quá hạn"). Không mặc định 'late' cho phần còn lại (khác với
            // FineService::formatFine bên admin) vì dữ liệu thật có reason như
            // "Làm rách trang sách" không chứa "hỏng/hư/mất" nhưng cũng không phải phí trễ
            // hạn — mặc định sai sẽ khiến UI áp công thức phí trễ hạn nhầm cho phí hỏng/mất.
            $reason = $row->reason ?? '';
            $type = 'other';
            if (str_contains($reason, 'mất')) {
                $type = 'lost';
            } elseif (str_contains($reason, 'hỏng') || str_contains($reason, 'hư') || str_contains($reason, 'rách')) {
                $type = 'damage';
            } elseif (str_contains($reason, 'trễ') || str_contains($reason, 'quá hạn')) {
                $type = 'late';
            }

            // charged_days_late + amount hiển thị: PHÂN BIỆT rõ 2 trường hợp — chỉ áp dụng
            // cho phí trễ hạn (type='late').
            //
            // - ĐÃ THANH TOÁN (status='paid'): khoản phí đã CHỐT LỊCH SỬ, không được tính
            //   lại theo ngày hiện tại (Case C). Số ngày = amount đã lưu / fine_per_day
            //   (không round mù quáng — nếu không chia hết chính xác, trả null). amount
            //   hiển thị = đúng fines.amount đã thanh toán, giữ nguyên vĩnh viễn.
            //
            // - CHƯA THANH TOÁN (status='unpaid'): fines.amount là số tiền ĐÓNG BĂNG lúc
            //   tạo fine, KHÔNG phản ánh tình trạng quá hạn thực tế hiện tại (root cause
            //   phát hiện qua audit thật: Mai Gia Bảo/fine 41 — amount cũ=5.000đ ứng với
            //   "1 ngày" trong khi trang "Đang mượn" cho cùng borrow_id/copy_id đang hiển
            //   thị quá hạn 867 ngày). Nguồn sự thật đúng phải tính RUNTIME từ dữ liệu
            //   mượn/trả thật, dùng ĐÚNG công thức trang "Đang mượn" đang dùng
            //   (BorrowingController::index(): DATEDIFF(effective_due_date, CURDATE()) —
            //   ngày dương lịch thuần, KHÔNG loại trừ ngày nghỉ) để 2 trang luôn khớp
            //   tuyệt đối cho cùng 1 borrow_id/copy_id. KHÔNG UPDATE DB ở đây (GET không có
            //   side effect) — amount trả về chỉ là giá trị COMPUTED để hiển thị, đồng thời
            //   dùng luôn để FE tính "Tổng tiền nợ" nhất quán (không SUM fines.amount cũ).
            //   Case A (chưa trả, return_date NULL): end = hôm nay, số ngày tăng theo thời
            //   gian đúng bản chất "đang nợ". Case B (đã trả, return_date có giá trị):
            //   end = return_date — đã chốt, không tăng nữa dù thời gian tiếp tục trôi.
            $amount = (float) $row->amount;
            $chargedDaysLate = null;
            $displayAmount = $amount;

            if ($type === 'late') {
                if ($row->status === 'paid') {
                    if ($finePerDay > 0 && $amount > 0) {
                        $daysExact = $amount / $finePerDay;
                        if (abs($daysExact - round($daysExact)) < 0.0001) {
                            $chargedDaysLate = (int) round($daysExact);
                        }
                    }
                } elseif ($row->due_date) {
                    $actualDaysLate  = OverdueCalculator::daysLate($row->due_date, $row->return_date);
                    $chargedDaysLate = $actualDaysLate;
                    $displayAmount   = $actualDaysLate * $finePerDay;
                }
            }

            return [
                'fine_id'           => $row->fine_id,
                'borrow_id'         => $row->borrow_id,
                'book_id'           => $row->book_id,
                'title'             => $row->title ?? '—',
                'cover_image'       => $row->cover_image,
                'type'              => $type,
                'status'            => $row->status === 'paid'
                    ? ['value' => 'paid',   'label' => 'Đã nộp']
                    : ['value' => 'unpaid', 'label' => 'Đang nợ'],
                'amount'            => $displayAmount,
                'reason'            => $row->reason,
                'borrow_date'       => $row->borrow_date,
                'due_date'          => $row->due_date,
                'return_date'       => $row->return_date,
                'payment_date'      => $row->payment_date,
                'charged_days_late' => $chargedDaysLate,
                'created_at'        => $row->created_at,
            ];
        });

        return response()->json(['data' => $data]);
    }
}
