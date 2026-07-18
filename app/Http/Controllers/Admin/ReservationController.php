<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateReservationRequest;
use App\Http\Requests\ConfirmReservationRequest;
use App\Http\Requests\MarkReadyRequest;
use App\Mail\ReservationAvailableMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ReservationController extends Controller
{
    /**
     * Số ngày độc giả có để đến nhận sách kể từ khi Reservation chuyển sang ready_for_pickup.
     */
    private const PICKUP_WINDOW_DAYS = 2;

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
                DB::raw('(SELECT COUNT(*) FROM reservations r WHERE r.book_id = b.book_id AND r.status = "pending" AND r.pickup_type = "online") AS queue_count'),
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
     * pickup_type được xác định tự động theo tồn kho:
     *   - Còn bản available  -> pickup_type = counter (đặt tại quầy, không xếp hàng)
     *   - Hết bản available  -> pickup_type = online  (xếp hàng chờ FIFO)
     *
     * Rules:
     *   1. User chưa có reservation active (pending/ready_for_pickup) cho cùng book
     *   2. User chưa đạt max_reservations_per_user
     */
    public function createReservation(CreateReservationRequest $request)
    {
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['max_reservations_per_user'])
            ->pluck('config_value', 'config_key');

        $maxPerUser = (int) ($settings['max_reservations_per_user'] ?? 3);
        $userId     = (int) $request->input('user_id');
        $bookId     = (int) $request->input('book_id');

        // [0] Validate library card
        $card = DB::table('library_cards')->where('user_id', $userId)->first();
        if (!$card) {
            return response()->json(['code' => 422, 'message' => 'Độc giả chưa có thẻ thư viện.'], 422);
        }
        if ((int) $card->status === 0) {
            return response()->json(['code' => 422, 'message' => 'Thẻ thư viện đã bị khóa.'], 422);
        }
        if ($card->expiry_date < now()->toDateString()) {
            return response()->json(['code' => 422, 'message' => 'Thẻ thư viện đã hết hạn.'], 422);
        }

        // [1] Kiểm tra user đã có reservation active cho book này chưa
        $existing = DB::table('reservations')
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->whereIn('status', ['pending', 'ready_for_pickup'])
            ->exists();

        if ($existing) {
            return response()->json([
                'code'    => 422,
                'message' => 'Độc giả đã có phiếu đặt trước sách này đang hoạt động.',
            ], 422);
        }

        // [2] Kiểm tra max reservations per user
        $activeCount = DB::table('reservations')
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'ready_for_pickup'])
            ->count();

        if ($activeCount >= $maxPerUser) {
            return response()->json([
                'code'    => 422,
                'message' => "Độc giả đã đạt giới hạn {$maxPerUser} phiếu đặt trước cùng lúc.",
            ], 422);
        }

        // [3] Xác định pickup_type theo tồn kho hiện tại
        $availableCount = DB::table('book_copies')
            ->where('book_id', $bookId)
            ->where('status', 'available')
            ->count();

        $pickupType = $availableCount > 0 ? 'counter' : 'online';

        // [4] queue_position chỉ có ý nghĩa với online (FIFO — max hiện tại + 1)
        $nextPosition = null;
        if ($pickupType === 'online') {
            $nextPosition = (int) DB::table('reservations')
                ->where('book_id', $bookId)
                ->where('status', 'pending')
                ->where('pickup_type', 'online')
                ->max('queue_position') + 1;
        }

        $title = DB::table('books')->where('book_id', $bookId)->value('title');

        $now           = Carbon::now();
        $reservationId = DB::table('reservations')->insertGetId([
            'user_id'        => $userId,
            'book_id'        => $bookId,
            'pickup_type'    => $pickupType,
            'queue_position' => $nextPosition,
            'status'         => 'pending',
            'notified_at'    => null,
            'expired_at'     => null,
            'created_at'     => $now,
        ]);

        $content = $pickupType === 'counter'
            ? "Bạn đã đặt trước sách '{$title}'. Vui lòng đến quầy thư viện để nhận sách."
            : "Bạn đã đặt trước sách '{$title}'. Vị trí hàng chờ: {$nextPosition}.";

        DB::table('notifications')->insert([
            'user_id'    => $userId,
            'title'      => 'Đặt trước thành công',
            'content'    => $content,
            'type'       => 'reservation',
            'is_read'    => 0,
            'created_at' => now(),
        ]);

        return response()->json([
            'code'    => 200,
            'message' => 'Đặt trước sách thành công.',
            'results' => ['object' => [
                'reservation_id' => $reservationId,
                'book_id'        => $bookId,
                'title'          => $title,
                'pickup_type'    => $pickupType,
                'queue_position' => $nextPosition,
                'status'         => 'pending',
                'created_at'     => $now->toDateTimeString(),
            ]],
        ]);
    }

    /**
     * GET /private/v1/reservation/list
     *
     * Danh sách phiếu đặt trước.
     * ?user_id=         (optional) lọc theo user
     * ?status=          (optional) lọc theo trạng thái
     * ?keyword=         (optional) tìm theo tên độc giả / mã thẻ / tên sách
     * ?from=, ?to=      (optional) lọc theo ngày đặt (r.created_at)
     * ?queue_position=  (optional) lọc theo số thứ tự hàng chờ (chỉ áp dụng nhóm pending+online)
     * actual_queue_position chỉ tính trong nhóm pending+online (những người thực sự đang xếp hàng).
     */
    public function listReservations(Request $request)
    {
        $userId        = $request->query('user_id');
        $status        = $request->query('status');
        $keyword       = trim((string) $request->query('keyword', ''));
        $from          = $request->query('from');
        $to            = $request->query('to');
        $queuePosition = $request->query('queue_position');
        $perPage       = min((int) $request->query('per_page', 20), 100);

        $query = DB::table('reservations as r')
            ->join('books as b', 'b.book_id', '=', 'r.book_id')
            ->join('users as u', 'u.user_id', '=', 'r.user_id')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'r.user_id')
            ->leftJoin('book_copies as bc', 'bc.copy_id', '=', 'r.copy_id')
            ->select([
                'r.reservation_id',
                'r.book_id',
                'r.user_id',
                'u.full_name',
                'lc.card_number',
                'b.title',
                'b.cover_image',
                'r.status',
                'r.pickup_type',
                'r.copy_id',
                'bc.shelf_location',
                'r.queue_position',
                'r.notified_at',
                'r.ready_at',
                'r.pickup_deadline',
                'r.expired_at',
                'r.created_at',
                DB::raw('(SELECT COUNT(*) + 1 FROM reservations r2
                    WHERE r2.book_id = r.book_id
                    AND r2.status = "pending" AND r2.pickup_type = "online"
                    AND r2.created_at < r.created_at) AS actual_queue_position'),
            ])
            ->orderBy('r.created_at', 'desc');

        if ($userId) {
            $query->where('r.user_id', (int) $userId);
        }
        if ($status) {
            $query->where('r.status', $status);
        }
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->where('u.full_name', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('lc.card_number', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('b.title', 'LIKE', '%' . $keyword . '%');
            });
        }
        if ($from) {
            $query->whereDate('r.created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('r.created_at', '<=', $to);
        }
        if ($queuePosition !== null && $queuePosition !== '') {
            // actual_queue_position chỉ có ý nghĩa với nhóm pending+online — ép luôn 2 điều
            // kiện này khi lọc theo hàng chờ để tránh so khớp nhầm với các phiếu khác nhóm.
            $query->where('r.status', 'pending')->where('r.pickup_type', 'online');
            $query->having('actual_queue_position', '=', (int) $queuePosition);
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
     * GET /private/v1/reservation/available-copies?book_id=
     *
     * Danh sách bản sao đang "available" của 1 cuốn sách, kèm vị trí kệ — để thủ thư
     * chọn đúng 1 bản sao khi "Xác nhận có sách" (mark-ready) hoặc xác nhận nhận sách
     * (confirm, nhánh pickup_type=counter), thay vì tự động chọn ngầm hoặc gõ tay copy_id.
     */
    public function availableCopiesByBook(Request $request)
    {
        $bookId = (int) $request->query('book_id');
        if (!$bookId) {
            return response()->json(['code' => 422, 'message' => 'book_id là bắt buộc.'], 422);
        }

        $copies = DB::table('book_copies as bc')
            ->where('bc.book_id', $bookId)
            ->where('bc.status', 'available')
            ->select(['bc.copy_id', 'bc.barcode', 'bc.condition', 'bc.shelf_location'])
            ->orderBy('bc.shelf_location')
            ->orderBy('bc.copy_id')
            ->get();

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $copies],
        ]);
    }

    /**
     * POST /private/v1/reservation/mark-ready
     *
     * "Xác nhận có sách" — chỉ áp dụng cho reservation pending + pickup_type=online.
     * Gán 1 bản sao cho reservation đứng đầu hàng chờ, khóa bản sao lại, và chuyển
     * reservation sang giai đoạn "đến quầy nhận" (ready_for_pickup).
     *
     * copy_id (optional): bản sao do thủ thư chọn từ danh sách available-copies. Nếu bỏ
     * trống, giữ nguyên hành vi cũ — tự động chọn bản sao available đầu tiên.
     */
    public function markReady(MarkReadyRequest $request)
    {
        $reservationId   = (int) $request->input('reservation_id');
        $requestedCopyId = $request->filled('copy_id') ? (int) $request->input('copy_id') : null;

        try {
            $result = DB::transaction(function () use ($reservationId, $requestedCopyId) {
                $reservation = DB::table('reservations')
                    ->where('reservation_id', $reservationId)
                    ->lockForUpdate()
                    ->first();

                if (!$reservation) {
                    throw new \RuntimeException('INVALID:Phiếu đặt trước không tồn tại.');
                }
                if ($reservation->status !== 'pending' || $reservation->pickup_type !== 'online') {
                    throw new \RuntimeException('INVALID:Phiếu đặt trước không ở trạng thái chờ online (trạng thái: ' . $reservation->status . '/' . $reservation->pickup_type . ').');
                }

                // Phải xác nhận đúng thứ tự hàng chờ (người đứng đầu trước)
                $firstInQueue = DB::table('reservations')
                    ->where('book_id', $reservation->book_id)
                    ->where('status', 'pending')
                    ->where('pickup_type', 'online')
                    ->orderBy('queue_position')
                    ->orderBy('created_at')
                    ->first();

                if ($firstInQueue && (int) $firstInQueue->reservation_id !== $reservationId) {
                    throw new \RuntimeException('INVALID:Phải xác nhận theo đúng thứ tự hàng chờ. Phiếu #' . $firstInQueue->reservation_id . ' đang đứng trước.');
                }

                if ($requestedCopyId) {
                    $copy = DB::table('book_copies')->where('copy_id', $requestedCopyId)->lockForUpdate()->first();
                    if (!$copy) {
                        throw new \RuntimeException('INVALID:Bản sao không tồn tại trong hệ thống.');
                    }
                    if ((int) $copy->book_id !== (int) $reservation->book_id) {
                        throw new \RuntimeException('INVALID:Bản sao không thuộc sách được đặt trước.');
                    }
                    if ($copy->status !== 'available') {
                        throw new \RuntimeException('INVALID:Bản sao không còn khả dụng (trạng thái: ' . $copy->status . ').');
                    }
                } else {
                    // Không truyền copy_id — hành vi cũ, tự động chọn bản sao available đầu tiên.
                    $copy = DB::table('book_copies')
                        ->where('book_id', $reservation->book_id)
                        ->where('status', 'available')
                        ->orderBy('copy_id')
                        ->lockForUpdate()
                        ->first();

                    if (!$copy) {
                        throw new \RuntimeException('INVALID:Hiện không có bản sao khả dụng cho sách này.');
                    }
                }

                $now      = now();
                $deadline = $now->copy()->addDays(self::PICKUP_WINDOW_DAYS);

                DB::table('book_copies')->where('copy_id', $copy->copy_id)->update(['status' => 'reserved']);

                DB::table('reservations')->where('reservation_id', $reservationId)->update([
                    'copy_id'         => $copy->copy_id,
                    'pickup_type'     => 'counter',
                    'status'          => 'ready_for_pickup',
                    'ready_at'        => $now,
                    'notified_at'     => $now,
                    'pickup_deadline' => $deadline,
                ]);

                $user  = DB::table('users')->where('user_id', $reservation->user_id)->first();
                $book  = DB::table('books')->where('book_id', $reservation->book_id)->first();
                $libraryName = config('app.name') ?? 'Thư Viện';

                if ($user && $user->email) {
                    try {
                        Mail::to($user->email)->send(new ReservationAvailableMail(
                            $user->full_name,
                            $book->title,
                            $libraryName,
                            $now->toDateTimeString(),
                            $deadline->toDateTimeString()
                        ));
                    } catch (\Throwable $e) {
                        // không chặn luồng nếu gửi mail lỗi — notification in-app vẫn được tạo
                    }
                }

                DB::table('notifications')->insert([
                    'user_id'    => $reservation->user_id,
                    'title'      => 'Sách đặt trước đã có sẵn',
                    'content'    => "Sách bạn đặt trước đã có sẵn. Vui lòng đến thư viện trong vòng " . self::PICKUP_WINDOW_DAYS . " ngày để nhận sách.",
                    'type'       => 'reservation',
                    'is_read'    => 0,
                    'created_at' => now(),
                ]);

                return [
                    'reservation_id'  => $reservationId,
                    'copy_id'         => $copy->copy_id,
                    'book_title'      => $book->title,
                    'ready_at'        => $now->toDateTimeString(),
                    'pickup_deadline' => $deadline->toDateTimeString(),
                ];
            });

            return response()->json([
                'code'    => 200,
                'message' => 'Đã xác nhận có sách, độc giả có ' . self::PICKUP_WINDOW_DAYS . ' ngày để đến nhận.',
                'results' => ['object' => $result],
            ]);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'INVALID:')) {
                return response()->json(['code' => 422, 'message' => substr($msg, strlen('INVALID:'))], 422);
            }
            throw $e;
        }
    }

    /**
     * POST /private/v1/reservation/confirm
     *
     * "Xác nhận đã nhận sách" — chỉ lúc này mới tạo borrow_transaction + borrow_detail.
     *
     * Hai nhánh:
     *   - pending + counter (chưa gán copy): copy_id bắt buộc từ request (thủ thư chọn/scan).
     *   - ready_for_pickup (đã gán + khóa copy ở bước mark-ready): dùng reservation->copy_id,
     *     không cần copy_id từ request (nếu có gửi lên thì phải khớp).
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
        $requestedCopyId = $request->filled('copy_id') ? (int) $request->input('copy_id') : null;

        try {
            $result = DB::transaction(function () use (
                $reservationId, $requestedCopyId, $librarianId, $maxBorrowDays, $maxBooksPerUser
            ) {
                // [1] LOCK reservation
                $reservation = DB::table('reservations')
                    ->where('reservation_id', $reservationId)
                    ->lockForUpdate()
                    ->first();

                if (!$reservation) {
                    throw new \RuntimeException('INVALID:Phiếu đặt trước không tồn tại.');
                }

                // [2] Xác định copy_id theo đúng nhánh nghiệp vụ
                if ($reservation->status === 'ready_for_pickup') {
                    if (!$reservation->copy_id) {
                        throw new \RuntimeException('INVALID:Phiếu đặt trước chưa được gán bản sao.');
                    }
                    if ($reservation->pickup_deadline && Carbon::parse($reservation->pickup_deadline)->isPast()) {
                        throw new \RuntimeException('INVALID:Phiếu đặt trước đã quá hạn nhận sách.');
                    }
                    if ($requestedCopyId !== null && $requestedCopyId !== (int) $reservation->copy_id) {
                        throw new \RuntimeException('INVALID:Bản sao không khớp với bản sao đã được giữ cho phiếu này.');
                    }
                    $copyId = (int) $reservation->copy_id;
                    $copy   = DB::table('book_copies')->where('copy_id', $copyId)->lockForUpdate()->first();
                    if (!$copy || $copy->status !== 'reserved') {
                        throw new \RuntimeException('INVALID:Bản sao không còn ở trạng thái đã giữ chỗ (trạng thái: ' . ($copy->status ?? 'không tồn tại') . ').');
                    }
                } elseif ($reservation->status === 'pending' && $reservation->pickup_type === 'counter') {
                    if (!$requestedCopyId) {
                        throw new \RuntimeException('INVALID:Vui lòng chọn bản sao sách sẽ giao cho độc giả.');
                    }
                    $copyId = $requestedCopyId;
                    $copy   = DB::table('book_copies')->where('copy_id', $copyId)->lockForUpdate()->first();
                    if (!$copy) {
                        throw new \RuntimeException('INVALID:Bản sao không tồn tại trong hệ thống.');
                    }
                    if ((int) $copy->book_id !== (int) $reservation->book_id) {
                        throw new \RuntimeException('INVALID:Bản sao này không thuộc sách được đặt trước.');
                    }
                    if ($copy->status !== 'available') {
                        throw new \RuntimeException('INVALID:Bản sao này không sẵn sàng để cho mượn (trạng thái: ' . $copy->status . ').');
                    }
                } else {
                    throw new \RuntimeException('INVALID:Phiếu đặt trước không ở trạng thái có thể nhận sách (trạng thái: ' . $reservation->status . ').');
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

                // [7] UPDATE reservation → completed
                DB::table('reservations')->where('reservation_id', $reservationId)->update([
                    'status'      => 'completed',
                    'copy_id'     => $copyId,
                    'notified_at' => now(),
                ]);

                $bookTitle = DB::table('books')->where('book_id', $reservation->book_id)->value('title');
                DB::table('notifications')->insert([
                    'user_id'    => $userId,
                    'title'      => 'Đặt trước hoàn tất',
                    'content'    => "Bạn đã nhận thành công sách '{$bookTitle}'.",
                    'type'       => 'reservation',
                    'is_read'    => 0,
                    'created_at' => now(),
                ]);

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
                'message' => 'Xác nhận đã nhận sách thành công. Phiếu mượn đã được tạo.',
                'results' => ['object' => $result],
            ]);

        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'INVALID:')) {
                return response()->json([
                    'code'    => 422,
                    'message' => substr($msg, strlen('INVALID:')),
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
     * Hủy phiếu đặt trước (pending hoặc ready_for_pickup).
     * Nếu đang ready_for_pickup (đã khóa 1 bản sao riêng) thì giải phóng bản sao đó.
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

        if (!in_array($reservation->status, ['pending', 'ready_for_pickup'])) {
            return response()->json([
                'code'    => 422,
                'message' => 'Chỉ có thể hủy phiếu đang ở trạng thái chờ hoặc sẵn sàng nhận.',
            ], 422);
        }

        DB::transaction(function () use ($reservation) {
            DB::table('reservations')
                ->where('reservation_id', $reservation->reservation_id)
                ->update(['status' => 'cancelled']);

            if ($reservation->status === 'ready_for_pickup' && $reservation->copy_id) {
                DB::table('book_copies')
                    ->where('copy_id', $reservation->copy_id)
                    ->where('status', 'reserved')
                    ->update(['status' => 'available']);
            }
        });

        $title = DB::table('books')
            ->where('book_id', $reservation->book_id)
            ->value('title');

        DB::table('notifications')->insert([
            'user_id'    => $reservation->user_id,
            'title'      => 'Đã hủy đặt trước',
            'content'    => "Phiếu đặt trước sách '{$title}' đã được hủy.",
            'type'       => 'reservation',
            'is_read'    => 0,
            'created_at' => now(),
        ]);

        return response()->json([
            'code'    => 200,
            'message' => 'Đã hủy phiếu đặt trước.',
        ]);
    }

    /**
     * POST /private/v1/reservation/expire
     *
     * Hết hạn các phiếu ready_for_pickup đã quá pickup_deadline + giải phóng bản sao.
     * Thường được gọi bởi cron job / Artisan command (reservations:expire).
     */
    public function expireReservations()
    {
        $reservations = DB::table('reservations')
            ->where('status', 'ready_for_pickup')
            ->whereNotNull('pickup_deadline')
            ->where('pickup_deadline', '<', now())
            ->get();

        foreach ($reservations as $reservation) {
            DB::transaction(function () use ($reservation) {
                DB::table('reservations')
                    ->where('reservation_id', $reservation->reservation_id)
                    ->update(['status' => 'expired']);

                if ($reservation->copy_id) {
                    DB::table('book_copies')
                        ->where('copy_id', $reservation->copy_id)
                        ->where('status', 'reserved')
                        ->update(['status' => 'available']);
                }

                $title = DB::table('books')
                    ->where('book_id', $reservation->book_id)
                    ->value('title');

                DB::table('notifications')->insert([
                    'user_id'    => $reservation->user_id,
                    'title'      => 'Phiếu đặt trước đã hết hạn',
                    'content'    => "Phiếu đặt trước sách '{$title}' đã hết hạn.",
                    'type'       => 'reservation',
                    'is_read'    => 0,
                    'created_at' => now(),
                ]);
            });
        }

        return response()->json([
            'code' => 200,
            'message' => 'Đã hết hạn ' . $reservations->count() . ' phiếu đặt trước.',
            'results' => [
                'object' => [
                    'expired_count' => $reservations->count()
                ]
            ]
        ]);
    }
}
