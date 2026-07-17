<?php

namespace App\Http\Controllers\Admin;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReturnBookRequest;
use Illuminate\Support\Facades\DB;


class ReturnController extends Controller
{
    public function __construct(private \App\Services\FineService $fineService)
    {
    }

    /**
     * GET /private/v1/return/search-reader?keyword=
     *
     * Tìm độc giả đang có sách mượn chưa trả.
     * - Chỉ trả về reader có active_borrows > 0.
     * - has_overdue dựa trên due_date < CURDATE(), KHÔNG dùng borrow_transactions.status.
     */
    public function searchReader(Request $request)
    {
        $keyword = trim($request->input('keyword', ''));

        if (mb_strlen($keyword) < 2) {
            return response()->json([
                'code'    => 422,
                'message' => 'Vui lòng nhập ít nhất 2 ký tự để tìm kiếm.',
            ], 422);
        }

        $readers = DB::table('users as u')
            ->join('roles as r', 'r.role_id', '=', 'u.role_id')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'u.user_id')
            ->where('r.role_name', 'reader')
            ->where(function ($q) use ($keyword) {
                $q->where('u.full_name', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('lc.card_number', 'LIKE', '%' . $keyword . '%');
            })
            ->select([
                'u.user_id',
                'u.full_name',
                'u.email',
                'u.phone',
                'lc.card_number',
                DB::raw('(
                    SELECT COUNT(*)
                    FROM borrow_details bd
                    JOIN borrow_transactions bt ON bt.borrow_id = bd.borrow_id
                    WHERE bt.user_id = u.user_id
                      AND bd.return_date IS NULL
                ) AS active_borrows'),
                DB::raw('EXISTS(
                    SELECT 1
                    FROM borrow_details bd
                    JOIN borrow_transactions bt ON bt.borrow_id = bd.borrow_id
                    WHERE bt.user_id = u.user_id
                      AND bd.return_date IS NULL
                      AND COALESCE(bd.renewed_due_date, bt.due_date) < CURDATE()
                    LIMIT 1
                ) AS has_overdue'),
            ])
            ->having('active_borrows', '>', 0)
            ->orderBy('u.full_name')
            ->limit(10)
            ->get();

        $results = $readers->map(function ($row) {
            return [
                'user_id'        => $row->user_id,
                'full_name'      => $row->full_name,
                'email'          => $row->email,
                'phone'          => $row->phone,
                'library_card'   => $row->card_number
                    ? ['card_number' => $row->card_number]
                    : null,
                'active_borrows' => (int)  $row->active_borrows,
                'has_overdue'    => (bool) $row->has_overdue,
            ];
        });

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $results],
        ]);
    }

    /**
     * POST /private/v1/return/confirm
     *
     * Xử lý trả sách trong 1 atomic transaction:
     *   1. Đọc fine_per_day từ system_settings (ngoài tx, 1 query)
     *   2. lockForUpdate borrow_details + borrow_transactions (tránh race condition)
     *   3. Safety validate (PHP, không thêm query)
     *   4. Bulk UPDATE borrow_details theo từng nhóm tình trạng — set return_date +
     *      condition_return (ghi nhận ai làm hỏng sách khi nào)
     *   5. Bulk UPDATE book_copies → trạng thái theo tình trạng trả (available/
     *      maintenance/lost — xem $statusMap bên dưới)
     *   6. Đếm remaining per borrow_id (1 query)
     *   7. UPDATE borrow_transactions status (1 query per borrow_id)
     *   8. Fines trễ hạn: SELECT existing (1 query) + bulk INSERT / UPDATE
     *   9. Fines hư hỏng/mất: tạo qua FineService::createDamageFine() cho từng bản
     *      sao không ở tình trạng "good" — chạy SAU bước 8 để fine_id thủ công
     *      (max(fine_id)+1, TiDB không AUTO_INCREMENT) nhìn thấy các fine trễ hạn
     *      vừa insert ở bước 8, tránh sinh trùng fine_id.
     */
    public function confirmReturn(ReturnBookRequest $request)
    {
        // [0] Config — ngoài transaction, 1 query duy nhất
        $finePerDay = (int) DB::table('system_settings')
            ->where('config_key', 'fine_per_day')
            ->value('config_value');

        $userId = (int) $request->input('user_id');
        $items  = collect($request->input('items', []))->map(fn ($item) => [
            'copy_id'   => (int) $item['copy_id'],
            'condition' => (string) $item['condition'],
        ])->values();
        $copyIds = $items->pluck('copy_id')->all();

        try {
            $result = DB::transaction(function () use ($userId, $items, $copyIds, $finePerDay) {
                $today = Carbon::today();
                $conditionByCopy = $items->pluck('condition', 'copy_id');

                // [1] LOCK: borrow_details JOIN borrow_transactions — giữ lock đến hết tx
                $details = DB::table('borrow_details as bd')
                    ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
                    ->whereIn('bd.copy_id', $copyIds)
                    ->whereNull('bd.return_date')
                    ->select('bd.copy_id', 'bd.borrow_id', 'bd.renewed_due_date', 'bt.user_id', 'bt.due_date')
                    ->lockForUpdate()
                    ->get();

                // [2] SAFETY VALIDATE — sau khi lock, kiểm tra lại trong PHP
                $lockedIds = $details->pluck('copy_id')->toArray();
                if (!empty(array_diff($copyIds, $lockedIds))) {
                    throw new \RuntimeException('INVALID:some copies already returned or not found');
                }
                if ($details->contains(fn($d) => (int) $d->user_id !== $userId)) {
                    throw new \RuntimeException('INVALID:ownership mismatch');
                }

                $borrowGroups  = $details->groupBy('borrow_id');
                $borrowIds     = array_map('intval', $details->pluck('borrow_id')->unique()->values()->toArray());
                $detailsByCopy = $details->keyBy('copy_id');

                // [3] BULK UPDATE borrow_details theo từng nhóm tình trạng
                $copyIdsByCondition = collect($copyIds)->groupBy(fn ($copyId) => $conditionByCopy[$copyId] ?? 'good');
                foreach ($copyIdsByCondition as $condition => $copyIdsInGroup) {
                    DB::table('borrow_details')
                        ->whereIn('copy_id', $copyIdsInGroup->all())
                        ->whereNull('return_date')
                        ->update([
                            'return_date'      => $today->toDateString(),
                            'condition_return' => $condition,
                        ]);
                }

                // [4] BULK UPDATE book_copies → trạng thái theo tình trạng trả. "Tốt"/"Hư
                // nhẹ" lưu thông lại ngay; "Hư vừa"/"Hư nặng" tạm ngừng cho mượn tới khi
                // thủ thư xử lý; "Mất sách" loại khỏi tồn kho khả dụng.
                $statusMap = [
                    'good'   => 'available',
                    'minor'  => 'available',
                    'medium' => 'maintenance',
                    'heavy'  => 'maintenance',
                    'lost'   => 'lost',
                ];
                foreach ($copyIdsByCondition as $condition => $copyIdsInGroup) {
                    DB::table('book_copies')
                        ->whereIn('copy_id', $copyIdsInGroup->all())
                        ->update(['status' => $statusMap[$condition] ?? 'available']);
                }

                // [5] ĐẾM remaining unreturned copies per borrow_id (sau khi đã UPDATE)
                $remainingMap = DB::table('borrow_details')
                    ->whereIn('borrow_id', $borrowIds)
                    ->whereNull('return_date')
                    ->select('borrow_id', DB::raw('COUNT(*) as cnt'))
                    ->groupBy('borrow_id')
                    ->pluck('cnt', 'borrow_id');

                // [6] UPDATE borrow_transactions status
                $closedTransactions = [];
                foreach ($borrowGroups as $borrowId => $group) {
                    $due      = Carbon::parse($group->first()->renewed_due_date ?? $group->first()->due_date)->startOfDay();
                    $leftover = (int) ($remainingMap->get($borrowId) ?? 0);

                    if ($leftover === 0) {
                        $newStatus            = 'returned';
                        $closedTransactions[] = (int) $borrowId;
                    } elseif ($today->gt($due)) {
                        $newStatus = 'overdue';  // partial return nhưng đã quá hạn
                    } else {
                        $newStatus = 'borrowing';
                    }

                    DB::table('borrow_transactions')
                        ->where('borrow_id', (int) $borrowId)
                        ->update(['status' => $newStatus]);
                }

                // [7] FINES — per copy, 1 SELECT để tránh N+1
                // Kiểm tra theo borrow_id + copy_id + unpaid (không filter theo reason, tránh duplicate fine)
                $existingFines = DB::table('fines')
                    ->whereIn('borrow_id', $borrowIds)
                    ->whereIn('copy_id', $copyIds)
                    ->where('status', 'unpaid')
                    ->get()
                    ->keyBy(fn($f) => $f->borrow_id . '-' . $f->copy_id);

                $totalPenalty = 0;
                $fineInserts  = [];
                $fineUpdates  = [];  // [fine_id => ['amount' => ..., 'reason' => ...]]

                foreach ($details as $detail) {
                    $overdueDays = $this->calculateOverdueDays($detail->renewed_due_date ?? $detail->due_date, $today);
                    if ($overdueDays === 0) continue;

                    $fineAmt       = $overdueDays * $finePerDay;
                    $totalPenalty += $fineAmt;

                    $key      = $detail->borrow_id . '-' . $detail->copy_id;
                    $existing = $existingFines->get($key);
                    // Chỉ coi là "cùng một khoản phí trễ hạn cần cập nhật" nếu fine sẵn có
                    // cũng là phí trễ hạn (reason chứa "trễ"/"quá hạn") — tránh trường hợp
                    // hiếm gặp: copy đã có sẵn 1 fine hỏng/mất (unpaid) trùng borrow_id+copy_id,
                    // không được ghi đè/biến nó thành phí trễ hạn.
                    $existingIsLate = $existing && (
                        str_contains($existing->reason, 'trễ') || str_contains($existing->reason, 'quá hạn')
                    );

                    // reason phản ánh đúng số ngày đã chốt (VD "Trả trễ 867 ngày") — để
                    // reason không còn lệch với amount/số ngày thực tế (đúng nguồn gây audit
                    // trước đó, tránh trường hợp reason cũ "Trả trễ 1 ngày" nhưng amount đã
                    // được chốt theo hàng trăm ngày).
                    $chargedReason = "Trả trễ {$overdueDays} ngày";

                    if ($existingIsLate) {
                        // KHÔNG cộng dồn "existing->amount + fineAmt" (bug cũ từng khiến
                        // fine_id=43 tăng 20.000đ -> 4.305.000đ vì có sẵn 1 fine unpaid cho
                        // cùng borrow_id+copy_id). Nguồn sự thật cho khoản phí trễ hạn của
                        // LƯỢT TRẢ SÁCH này là overdueDays vừa tính (due_date thật -> hôm nay,
                        // tức return_date) — ghi đè bằng giá trị mới.
                        $fineUpdates[$existing->fine_id] = [
                            'amount' => $fineAmt,
                            'reason' => $chargedReason,
                        ];
                    } else {
                        $fineInserts[] = [
                            'user_id'    => $userId,
                            'borrow_id'  => (int) $detail->borrow_id,
                            'copy_id'    => (int) $detail->copy_id,
                            'amount'     => $fineAmt,
                            'reason'     => $chargedReason,
                            'status'     => 'unpaid',
                            'created_at' => now(),
                        ];
                    }
                }

                // Bulk INSERT (1 query) + UPDATE nếu cần
                if (!empty($fineInserts)) {
                    // TiDB: fine_id không có AUTO_INCREMENT -> tự sinh id tuần tự trước khi
                    // insert (cùng pattern đã dùng ở HolidayController/ActivityLogService).
                    $nextFineId = (int) (DB::table('fines')->lockForUpdate()->max('fine_id') ?? 0) + 1;
                    foreach ($fineInserts as &$fineRow) {
                        $fineRow['fine_id'] = $nextFineId++;
                    }
                    unset($fineRow);

                    DB::table('fines')->insert($fineInserts);
                }
                foreach ($fineUpdates as $fineId => $update) {
                    DB::table('fines')->where('fine_id', $fineId)->update($update);
                }

                // [8] FINES HƯ HỎNG/MẤT — tạo cho từng bản sao không ở tình trạng "good".
                // Chạy sau khối fine trễ hạn ở trên để FineService::createDamageFine()'s
                // max(fine_id)+1 nhìn thấy các fine trễ hạn vừa insert, tránh trùng fine_id.
                $totalDamage = 0;
                foreach ($items as $item) {
                    if ($item['condition'] === 'good') {
                        continue;
                    }
                    $detail       = $detailsByCopy->get($item['copy_id']);
                    $damageResult = $this->fineService->createDamageFine([
                        'user_id'      => $userId,
                        'copy_id'      => $item['copy_id'],
                        'borrow_id'    => $detail->borrow_id ?? null,
                        'damage_level' => $item['condition'],
                    ]);
                    $totalDamage += $damageResult['amount'];
                }

                Notification::create([
                    'user_id' => $userId,
                    'title' => 'Trả sách thành công',
                    'content' => 'Thư viện đã xác nhận bạn trả sách thành công.',
                    'type' => 'return',
                    'is_read' => 0,
                ]);

                // Bản sao vừa trả chuyển trạng thái theo tình trạng (bước [4]) — "Tốt"/"Hư
                // nhẹ" thành 'available'. Việc gán bản sao 'available' cho reservation đang
                // chờ (nếu có) là thao tác thủ công của thủ thư qua nút "Xác nhận có sách"
                // trên màn hình Reservation (Admin\ReservationController::markReady), không
                // tự động ở đây — tránh khóa nhầm bản sao cho một reservation mà thủ thư
                // chưa chủ động xác nhận.

                return [
                    'return_date'          => $today->toDateString(),
                    'returned_books_count' => count($copyIds),
                    'total_penalty'        => $totalPenalty,
                    'total_damage'         => $totalDamage,
                    'closed_transactions'  => $closedTransactions,
                ];
            });

            return response()->json([
                'code'    => 200,
                'message' => 'Trả sách thành công.',
                'results' => ['object' => $result],
            ]);

        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'INVALID:')) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'Một hoặc nhiều bản sao không hợp lệ hoặc đã được trả.',
                ], 422);
            }
            throw $e;
        }
    }

    /**
     * GET /private/v1/return/validate/{barcode}?user_id={id}
     *
     * Validation flow (thứ tự cứng):
     *   1. Barcode tồn tại → 404
     *   2. Copy đang được mượn (active borrow) → 422 "chưa được mượn"
     *   3. Borrow thuộc đúng user_id → 422 "không thuộc độc giả"
     *   4. Thành công → trả về overdue_days + penalty_fee
     *
     * READ-ONLY — không ghi database.
     */
    public function validateReturnCopy(string $barcode, Request $request)
    {
        $userId = $request->query('user_id');

        if (!$userId || !is_numeric($userId)) {
            return response()->json([
                'code'    => 422,
                'message' => 'Tham số user_id là bắt buộc và phải là số nguyên.',
            ], 422);
        }

        // Single query — LEFT JOIN để phân biệt đủ 3 case lỗi mà không cần nhiều query
        $row = DB::table('book_copies as bc')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('borrow_details as bd', function ($join) {
                $join->on('bd.copy_id', '=', 'bc.copy_id')
                     ->whereNull('bd.return_date');
            })
            ->leftJoin('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
            ->where('bc.barcode', $barcode)
            ->select([
                'bc.copy_id',
                'bc.barcode',
                'b.title',
                'bd.borrow_id',
                'bd.renewed_due_date',
                'bt.due_date',
                'bt.user_id as borrower_id',
            ])
            ->first();

        // [1] Barcode không tồn tại
        if (!$row) {
            return response()->json([
                'code'    => 404,
                'message' => 'Barcode không tồn tại trong hệ thống.',
            ], 404);
        }

        // [2] Copy không có active borrow
        if (is_null($row->borrow_id)) {
            return response()->json([
                'code'    => 422,
                'message' => 'Sách này chưa được mượn.',
            ], 422);
        }

        // [3] Borrow không thuộc về user_id được chỉ định
        if ((int) $row->borrower_id !== (int) $userId) {
            return response()->json([
                'code'    => 422,
                'message' => 'Sách này không thuộc độc giả được chọn.',
            ], 422);
        }

        // Tính overdue — Carbon 3.x: phải truyền absolute=true tường minh
        $finePerDay  = (int) DB::table('system_settings')
            ->where('config_key', 'fine_per_day')
            ->value('config_value');

        $today          = Carbon::today();
        $effectiveDue   = $row->renewed_due_date ?? $row->due_date;
        $overdueDays    = $this->calculateOverdueDays($effectiveDue, $today);

        return response()->json([
            'code'    => 200,
            'results' => [
                'object' => [
                    'copy_id'      => $row->copy_id,
                    'barcode'      => $row->barcode,
                    'title'        => $row->title,
                    'borrow_id'    => $row->borrow_id,
                    'due_date'     => $effectiveDue,
                    'overdue_days' => $overdueDays,
                    'penalty_fee'  => $overdueDays * $finePerDay,
                ],
            ],
        ]);
    }

    /**
     * GET /private/v1/return/borrowed-books/{user_id}
     *
     * Trả danh sách tất cả bản sao đang mượn (return_date IS NULL) của một độc giả.
     * overdue_days và penalty_fee tính trong PHP, fine_per_day đọc từ system_settings.
     */
    public function getBorrowedBooks(int $userId)
    {
        $user = DB::table('users as u')
            ->join('roles as r', 'r.role_id', '=', 'u.role_id')
            ->where('u.user_id', $userId)
            ->where('r.role_name', 'reader')
            ->select('u.user_id', 'u.full_name')
            ->first();

        if (!$user) {
            return response()->json([
                'code'    => 404,
                'message' => 'Độc giả không tồn tại.',
            ], 404);
        }

        // 1 query đọc cả fine_per_day và max_renew_times
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['fine_per_day', 'max_renew_times'])
            ->pluck('config_value', 'config_key');

        $finePerDay    = (int) ($settings['fine_per_day']    ?? 5000);
        $maxRenewTimes = (int) ($settings['max_renew_times'] ?? 2);

        // Single JOIN query — không N+1, thêm renew_count và book_id cho renew module
        $rows = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bt.user_id', $userId)
            ->whereNull('bd.return_date')
            ->select([
                'bt.borrow_id',
                'bt.borrow_date',
                'bt.due_date',
                'bd.copy_id',
                'bd.renew_count',
                'bd.renewed_due_date',
                'bc.barcode',
                'bc.book_id',
                'b.title',
            ])
            ->orderBy(DB::raw('COALESCE(bd.renewed_due_date, bt.due_date)'), 'asc')
            ->orderBy('bt.borrow_id', 'asc')
            ->get();

        // Check active reservations cho các book_id đang mượn (1 query)
        $bookIds = $rows->pluck('book_id')->unique()->toArray();
        $reservedBookIds = !empty($bookIds)
            ? DB::table('reservations')
                ->whereIn('book_id', $bookIds)
                ->where('status', 'waiting')
                ->pluck('book_id')
                ->flip()  // dùng làm lookup set O(1)
                ->toArray()
            : [];

        $today = Carbon::today();

        $results = $rows->map(function ($row) use ($finePerDay, $maxRenewTimes, $reservedBookIds, $today) {
            $effectiveDue = $row->renewed_due_date ?? $row->due_date;
            $overdueDays  = $this->calculateOverdueDays($effectiveDue, $today);
            $renewCount   = (int) $row->renew_count;

            return [
                'borrow_id'    => $row->borrow_id,
                'copy_id'      => $row->copy_id,
                'barcode'      => $row->barcode,
                'title'        => $row->title,
                'borrow_date'  => $row->borrow_date,
                'due_date'     => $effectiveDue,
                'overdue_days' => $overdueDays,
                'penalty_fee'  => $overdueDays * $finePerDay,
                'renew_count'  => $renewCount,
                'can_renew'    => $renewCount < $maxRenewTimes && !isset($reservedBookIds[$row->book_id]),
            ];
        });

        return response()->json([
            'code'    => 200,
            'results' => [
                'objects' => $results,
                'meta'    => ['max_renew_times' => $maxRenewTimes],
            ],
        ]);
    }

    // Delegate sang OverdueCalculator — nguồn sự thật DUY NHẤT cho "số ngày quá hạn" trong
    // toàn hệ thống (đã xác nhận với người dùng: một công thức chung, KHÔNG loại trừ ngày
    // nghỉ, áp dụng thống nhất cho BorrowingController/FineController/ReturnController/
    // FineService/backfill command). Trước đây hàm này tự trừ ngày nghỉ riêng — bỏ để
    // tránh duy trì công thức thứ hai khác với "Đang mượn"/"Lịch sử phí".
    private function calculateOverdueDays(string $dueDateStr, \Carbon\Carbon $today): int
    {
        return \App\Services\OverdueCalculator::daysLate($dueDateStr, $today->toDateString());
    }
}
