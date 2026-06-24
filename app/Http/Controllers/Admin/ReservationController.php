<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReservationRequest;
use App\Http\Requests\ConfirmReservationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReservationController extends Controller
{
    /**
     * GET /private/v1/reservation/search-book?keyword=
     *
     * Tìm sách để đặt trước: trả về availability + số reservation đang chờ.
     */
    public function searchBook(Request $request)
    {
        $keyword = trim($request->query('keyword', ''));
        if (strlen($keyword) < 1) {
            return response()->json(['code' => 200, 'results' => ['objects' => []]]);
        }

        $books = DB::table('books as b')
            ->leftJoin('authors as a', 'a.author_id', '=', 'b.author_id')
            ->select([
                'b.book_id',
                'b.title',
                'b.cover_image',
                DB::raw('COALESCE(a.author_name, "") AS author_name'),
                DB::raw('(SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id AND bc.status = "available") AS available_copies'),
                DB::raw('(SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id) AS total_copies'),
                DB::raw('(SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.status IN ("waiting","ready")) AS queue_count'),
            ])
            ->where(function ($q) use ($keyword) {
                $q->where('b.title', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('b.isbn', 'LIKE', '%' . $keyword . '%');
            })
            ->orderByRaw('available_copies ASC, b.title ASC')
            ->limit(15)
            ->get();

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $books],
        ]);
    }

    /**
     * POST /private/v1/reservation/create
     *
     * Tạo phiếu đặt trước sách.
     *
     * Rules:
     *   1. Sách không có bản copy available
     *   2. User chưa có reservation active (waiting/ready) cho cùng book
     *   3. User chưa đạt max_reservations_per_user
     */
    public function createReservation(CreateReservationRequest $request)
    {
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['max_reservations_per_user'])
            ->pluck('config_value', 'config_key');

        $maxPerUser = (int) ($settings['max_reservations_per_user'] ?? 3);
        $userId     = (int) $request->input('user_id');
        $bookId     = (int) $request->input('book_id');

        // [1] Check available copies
        $availableCount = DB::table('book_copies')
            ->where('book_id', $bookId)
            ->where('status', 'available')
            ->count();

        if ($availableCount > 0) {
            return response()->json([
                'code'    => 422,
                'message' => "Sách hiện còn {$availableCount} bản copy sẵn có, vui lòng đến quầy để mượn trực tiếp.",
            ], 422);
        }

        // [2] Kiểm tra user đã có reservation active cho book này chưa
        $existing = DB::table('reservations')
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->whereIn('status', ['waiting', 'ready'])
            ->exists();

        if ($existing) {
            return response()->json([
                'code'    => 422,
                'message' => 'Độc giả đã có phiếu đặt trước sách này đang hoạt động.',
            ], 422);
        }

        // [3] Kiểm tra max reservations per user
        $activeCount = DB::table('reservations')
            ->where('user_id', $userId)
            ->whereIn('status', ['waiting', 'ready'])
            ->count();

        if ($activeCount >= $maxPerUser) {
            return response()->json([
                'code'    => 422,
                'message' => "Độc giả đã đạt giới hạn {$maxPerUser} phiếu đặt trước cùng lúc.",
            ], 422);
        }

        // [4] Tính queue_position (FIFO — max hiện tại + 1)
        $nextPosition = (int) DB::table('reservations')
            ->where('book_id', $bookId)
            ->whereIn('status', ['waiting', 'ready'])
            ->max('queue_position') + 1;

        $now           = Carbon::now();
        $reservationId = DB::table('reservations')->insertGetId([
            'user_id'        => $userId,
            'book_id'        => $bookId,
            'queue_position' => $nextPosition,
            'status'         => 'waiting',
            'notified_at'    => null,
            'expired_at'     => null,
            'created_at'     => $now,
        ]);

        $title = DB::table('books')->where('book_id', $bookId)->value('title');

        return response()->json([
            'code'    => 200,
            'message' => 'Đặt trước sách thành công.',
            'results' => ['object' => [
                'reservation_id' => $reservationId,
                'book_id'        => $bookId,
                'title'          => $title,
                'queue_position' => $nextPosition,
                'status'         => 'waiting',
                'created_at'     => $now->toDateTimeString(),
            ]],
        ]);
    }

    /**
     * GET /private/v1/reservation/list
     *
     * Danh sách phiếu đặt trước.
     * ?user_id= (optional) lọc theo user
     * ?status=  (optional) lọc theo trạng thái
     * Tính actual_queue_position thực tế (bỏ qua cancelled/expired).
     */
    public function listReservations(Request $request)
    {
        $userId  = $request->query('user_id');
        $status  = $request->query('status');
        $perPage = min((int) $request->query('per_page', 20), 100);

        $query = DB::table('reservations as r')
            ->join('books as b', 'b.book_id', '=', 'r.book_id')
            ->join('users as u', 'u.user_id', '=', 'r.user_id')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'r.user_id')
            ->select([
                'r.reservation_id',
                'r.book_id',
                'r.user_id',
                'u.full_name',
                'lc.card_number',
                'b.title',
                'b.cover_image',
                'r.status',
                'r.queue_position',
                'r.notified_at',
                'r.expired_at',
                'r.created_at',
                DB::raw('(SELECT COUNT(*) + 1 FROM reservations r2
                    WHERE r2.book_id = r.book_id
                    AND r2.status IN ("waiting","ready")
                    AND r2.created_at < r.created_at) AS actual_queue_position'),
            ])
            ->orderBy('r.created_at', 'desc');

        if ($userId) {
            $query->where('r.user_id', (int) $userId);
        }
        if ($status) {
            $query->where('r.status', $status);
        }

        $paginated = $query->paginate($perPage);

        return response()->json([
            'code'    => 200,
            'results' => [
                'objects'  => $paginated->items(),
                'total'    => $paginated->total(),
                'per_page' => $paginated->perPage(),
                'page'     => $paginated->currentPage(),
            ],
        ]);
    }

    /**
     * POST /private/v1/reservation/confirm
     *
     * Xác nhận đặt trước tại quầy → tạo borrow_transaction + borrow_detail.
     *
     * Transaction flow:
     *   1. Lock reservation + book_copies
     *   2. Validate status (waiting/ready), copy đúng book + available
     *   3. Validate max_books_per_user
     *   4. INSERT borrow_transaction
     *   5. INSERT borrow_detail
     *   6. UPDATE book_copies → borrowed
     *   7. UPDATE reservation → converted
     */
    public function confirmReservation(ConfirmReservationRequest $request)
    {
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['max_borrow_days', 'max_books_per_user'])
            ->pluck('config_value', 'config_key');

        $maxBorrowDays   = (int) ($settings['max_borrow_days']   ?? 14);
        $maxBooksPerUser = (int) ($settings['max_books_per_user'] ?? 5);
        $librarianId     = auth()->id();
        $reservationId   = (int) $request->input('reservation_id');
        $copyId          = (int) $request->input('copy_id');

        try {
            $result = DB::transaction(function () use (
                $reservationId, $copyId, $librarianId, $maxBorrowDays, $maxBooksPerUser
            ) {
                // [1] LOCK
                $reservation = DB::table('reservations')
                    ->where('reservation_id', $reservationId)
                    ->lockForUpdate()
                    ->first();

                $copy = DB::table('book_copies')
                    ->where('copy_id', $copyId)
                    ->lockForUpdate()
                    ->first();

                // [2] VALIDATE reservation
                if (!in_array($reservation->status, ['waiting', 'ready'])) {
                    throw new \RuntimeException('INVALID:reservation not active (status=' . $reservation->status . ')');
                }
                if ((int) $copy->book_id !== (int) $reservation->book_id) {
                    throw new \RuntimeException('INVALID:copy does not belong to reservation book');
                }
                if ($copy->status !== 'available') {
                    throw new \RuntimeException('INVALID:copy not available (status=' . $copy->status . ')');
                }

                $userId = (int) $reservation->user_id;

                // [3] VALIDATE max borrow per user
                $activeBorrowCount = DB::table('borrow_details as bd')
                    ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
                    ->where('bt.user_id', $userId)
                    ->whereNull('bd.return_date')
                    ->count();

                if ($activeBorrowCount >= $maxBooksPerUser) {
                    throw new \RuntimeException('BORROW_LIMIT:max books per user reached');
                }

                $today   = Carbon::today();
                $dueDate = $today->copy()->addDays($maxBorrowDays);

                // [4] INSERT borrow_transaction
                $borrowId = DB::table('borrow_transactions')->insertGetId([
                    'user_id'      => $userId,
                    'librarian_id' => $librarianId,
                    'borrow_date'  => $today->toDateString(),
                    'due_date'     => $dueDate->toDateString(),
                    'status'       => 'borrowing',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                // [5] INSERT borrow_detail
                DB::table('borrow_details')->insert([
                    'borrow_id'        => $borrowId,
                    'copy_id'          => $copyId,
                    'renew_count'      => 0,
                    'return_date'      => null,
                    'condition_return' => null,
                ]);

                // [6] UPDATE book_copies → borrowed
                DB::table('book_copies')->where('copy_id', $copyId)->update(['status' => 'borrowed']);

                // [7] UPDATE reservation → converted
                DB::table('reservations')->where('reservation_id', $reservationId)->update([
                    'status'      => 'converted',
                    'notified_at' => now(),
                ]);

                $bookTitle = DB::table('books')->where('book_id', $reservation->book_id)->value('title');

                return [
                    'borrow_id'   => $borrowId,
                    'user_id'     => $userId,
                    'copy_id'     => $copyId,
                    'book_title'  => $bookTitle,
                    'borrow_date' => $today->toDateString(),
                    'due_date'    => $dueDate->toDateString(),
                ];
            });

            return response()->json([
                'code'    => 200,
                'message' => 'Xác nhận đặt trước thành công. Phiếu mượn đã được tạo.',
                'results' => ['object' => $result],
            ]);

        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'INVALID:')) {
                return response()->json([
                    'code'    => 422,
                    'message' => 'Phiếu đặt trước hoặc bản sao không hợp lệ.',
                ], 422);
            }
            if (str_starts_with($msg, 'BORROW_LIMIT:')) {
                return response()->json([
                    'code'    => 422,
                    'message' => "Độc giả đã đạt giới hạn {$maxBooksPerUser} cuốn sách mượn cùng lúc.",
                ], 422);
            }
            throw $e;
        }
    }

    /**
     * POST /private/v1/reservation/cancel
     *
     * Hủy phiếu đặt trước (waiting hoặc ready).
     */
    public function cancelReservation(Request $request)
    {
        $reservationId = (int) $request->input('reservation_id');
        if (!$reservationId) {
            return response()->json(['code' => 422, 'message' => 'reservation_id là bắt buộc.'], 422);
        }

        $reservation = DB::table('reservations')
            ->where('reservation_id', $reservationId)
            ->first();

        if (!$reservation) {
            return response()->json(['code' => 404, 'message' => 'Phiếu đặt trước không tồn tại.'], 404);
        }

        if (!in_array($reservation->status, ['waiting', 'ready'])) {
            return response()->json([
                'code'    => 422,
                'message' => 'Chỉ có thể hủy phiếu đang ở trạng thái chờ hoặc sẵn sàng.',
            ], 422);
        }

        DB::table('reservations')
            ->where('reservation_id', $reservationId)
            ->update(['status' => 'cancelled']);

        return response()->json([
            'code'    => 200,
            'message' => 'Đã hủy phiếu đặt trước.',
        ]);
    }

    /**
     * POST /private/v1/reservation/expire
     *
     * Hết hạn các phiếu ready đã quá expired_at.
     * Thường được gọi bởi cron job / Artisan command.
     */
    public function expireReservations()
    {
        $count = DB::table('reservations')
            ->where('status', 'ready')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->update(['status' => 'expired']);

        return response()->json([
            'code'    => 200,
            'message' => "Đã hết hạn {$count} phiếu đặt trước.",
            'results' => ['object' => ['expired_count' => $count]],
        ]);
    }
}
