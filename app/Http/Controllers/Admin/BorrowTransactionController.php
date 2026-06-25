<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use App\Http\Requests\BorrowBookRequest;

class BorrowTransactionController extends Controller
{
    /**
     * GET /private/v1/checkout/find-reader?keyword=
     *
     * Tìm độc giả theo tên hoặc số thẻ thư viện.
     * Trả kèm trạng thái mượn và cảnh báo để frontend quyết định có cho mượn không.
     */
    public function findReader(Request $request)
    {
        $keyword = trim($request->input('keyword', ''));

        if (mb_strlen($keyword) < 2) {
            return response()->json([
                'code'    => 422,
                'message' => 'Vui lòng nhập ít nhất 2 ký tự để tìm kiếm.',
            ], 422);
        }

        // Đọc hạn mức từ system_settings (ưu tiên), dùng sau trong vòng map
        $systemBorrowLimit = DB::table('system_settings')
            ->where('config_key', 'max_books_per_user')
            ->value('config_value');

        // Query 2: readers — borrowing_count và unpaid_fines đưa vào subquery
        // để tránh N+1 (không query trong map/foreach)
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
                'lc.card_id',
                'lc.card_number',
                'lc.status as card_status',
                'lc.expiry_date',
                'lc.borrow_limit',
                DB::raw('(
                    SELECT COUNT(*)
                    FROM borrow_details bd
                    JOIN borrow_transactions bt ON bt.borrow_id = bd.borrow_id
                    WHERE bt.user_id = u.user_id
                      AND bd.return_date IS NULL
                ) AS borrowing_count'),
                DB::raw("(
                    SELECT COALESCE(SUM(f.amount), 0)
                    FROM fines f
                    WHERE f.user_id = u.user_id
                      AND f.status = 'unpaid'
                ) AS unpaid_fines"),
            ])
            ->orderBy('u.full_name')
            ->limit(10)
            ->get();

        $today = now()->toDateString();

        $results = $readers->map(function ($row) use ($today, $systemBorrowLimit) {
            $hasCard = !is_null($row->card_id);

            // Lấy trực tiếp từ subquery — không query thêm trong map
            $borrowingCount = (int) $row->borrowing_count;
            $unpaidFines    = (float) $row->unpaid_fines;

            // Hạn mức: ưu tiên system_settings.max_borrow_books, fallback library_cards.borrow_limit
            $borrowLimit = $systemBorrowLimit !== null
                ? (int) $systemBorrowLimit
                : (int) ($row->borrow_limit ?? 5);

            // Thu thập tất cả cảnh báo (không dừng ở cảnh báo đầu tiên)
            $warnings = [];

            if (!$hasCard) {
                $warnings[] = 'Độc giả chưa có thẻ thư viện';
            } else {
                if ((int) $row->card_status === 0) {
                    $warnings[] = 'Thẻ thư viện đã bị khóa';
                }
                if ($row->expiry_date < $today) {
                    $warnings[] = 'Thẻ thư viện đã hết hạn';
                }
                if ($unpaidFines > 0) {
                    $warnings[] = 'Độc giả còn phí chưa thanh toán';
                }
                if ($borrowingCount >= $borrowLimit) {
                    $warnings[] = 'Đã đạt giới hạn số sách được mượn';
                }
            }

            return [
                'user_id'        => $row->user_id,
                'full_name'      => $row->full_name,
                'email'          => $row->email,
                'phone'          => $row->phone,
                'library_card'   => $hasCard ? [
                    'card_id'     => $row->card_id,
                    'card_number' => $row->card_number,
                    'status'      => (int) $row->card_status,
                    'expiry_date' => $row->expiry_date,
                ] : null,
                'borrowing_count' => $borrowingCount,
                'borrow_limit'    => $borrowLimit,
                'unpaid_fines'    => $unpaidFines,
                'can_borrow'      => empty($warnings),
                'warnings'        => $warnings,
            ];
        });

        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $results],
        ]);
    }

    /**
     * GET /private/v1/checkout/available-copies?q={query}
     *
     * Tìm kiếm bản sao sách khả dụng theo tên sách, barcode, hoặc ISBN.
     * Dùng cho autocomplete ô quét barcode khi thủ thư không biết barcode chính xác.
     */
    public function searchAvailableCopies(Request $request)
    {
        $q = trim($request->input('q', ''));
        if (mb_strlen($q) < 1) {
            return response()->json(['code' => 200, 'results' => ['objects' => []]]);
        }

        $copies = DB::table('book_copies as bc')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('book_authors as ba', 'ba.book_id', '=', 'b.book_id')
            ->leftJoin('authors as a', 'a.author_id', '=', 'ba.author_id')
            ->where('bc.status', 'available')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('borrow_details as bd')
                    ->whereColumn('bd.copy_id', 'bc.copy_id')
                    ->whereNull('bd.return_date');
            })
            ->where(function ($w) use ($q) {
                $w->where('bc.barcode', 'LIKE', '%' . $q . '%')
                  ->orWhere('b.title', 'LIKE', '%' . $q . '%')
                  ->orWhere('b.isbn', 'LIKE', '%' . $q . '%');
            })
            ->select([
                'bc.copy_id',
                'bc.barcode',
                'bc.condition',
                'b.book_id',
                'b.title',
                DB::raw("COALESCE(a.author_name, '') as author_name"),
            ])
            ->groupBy('bc.copy_id', 'bc.barcode', 'bc.condition', 'b.book_id', 'b.title', 'a.author_name')
            ->orderBy('b.title')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'copy_id'   => $r->copy_id,
                'barcode'   => $r->barcode,
                'condition' => $r->condition,
                'book_id'   => $r->book_id,
                'title'     => $r->title,
                'author'    => $r->author_name,
            ]);

        return response()->json(['code' => 200, 'results' => ['objects' => $copies]]);
    }

    /**
     * GET /private/v1/checkout/copy/{barcode}
     *
     * Kiểm tra bản sao sách có thể mượn được không.
     * Xác nhận kép: status trên book_copies VÀ giao dịch thực tế trên borrow_details.
     */
    public function validateCopy(string $barcode)
    {
        // Query 1: tìm bản sao + thông tin sách
        $copy = DB::table('book_copies as bc')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bc.barcode', $barcode)
            ->select([
                'bc.copy_id',
                'bc.barcode',
                'bc.status',
                'bc.condition',
                'bc.shelf_location',
                'b.book_id',
                'b.title',
                'b.cover_image',
            ])
            ->first();

        if (!$copy) {
            return response()->json([
                'code'    => 404,
                'message' => 'Không tìm thấy barcode trong hệ thống.',
            ], 404);
        }

        // Kiểm tra status — từ giá trị thực tế trong DB: available/borrowed/reserved/maintenance/lost
        if ($copy->status !== 'available') {
            $statusMessages = [
                'borrowed'    => 'Bản sao sách đang được mượn.',
                'reserved'    => 'Bản sao sách đang được đặt trước.',
                'maintenance' => 'Bản sao sách đang bảo trì.',
                'lost'        => 'Bản sao sách đã bị mất.',
            ];

            $message = $statusMessages[$copy->status]
                ?? 'Bản sao sách không khả dụng (trạng thái: ' . $copy->status . ').';

            return response()->json([
                'code'    => 422,
                'message' => $message,
            ], 422);
        }

        // Query 2: cross-check borrow_details — không chỉ tin vào book_copies.status
        // Dữ liệu có thể lệch: status='available' nhưng vẫn còn return_date IS NULL
        $hasActiveBorrow = DB::table('borrow_details')
            ->where('copy_id', $copy->copy_id)
            ->whereNull('return_date')
            ->exists();

        if ($hasActiveBorrow) {
            return response()->json([
                'code'    => 422,
                'message' => 'Bản sao sách đang có giao dịch mượn chưa hoàn trả.',
            ], 422);
        }

        return response()->json([
            'code'    => 200,
            'results' => [
                'object' => [
                    'copy_id'        => $copy->copy_id,
                    'barcode'        => $copy->barcode,
                    'status'         => $copy->status,
                    'condition'      => $copy->condition,
                    'shelf_location' => $copy->shelf_location,
                    'book'           => [
                        'book_id'     => $copy->book_id,
                        'title'       => $copy->title,
                        'cover_image' => $copy->cover_image,
                    ],
                ],
            ],
        ]);
    }

    /**
     * POST /private/v1/checkout/store
     *
     * Tạo giao dịch mượn sách.
     * Validation thứ tự: config → reader → hạn mức → phí → bản sao → transaction.
     * Chống race condition bằng lockForUpdate() bên trong DB::transaction().
     */
    public function store(BorrowBookRequest $request)
    {
        // [1] Đọc config — 1 query
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['max_borrow_days', 'max_books_per_user'])
            ->pluck('config_value', 'config_key');

        if (!isset($settings['max_borrow_days'], $settings['max_books_per_user'])) {
            return response()->json([
                'code'    => 500,
                'message' => 'Cấu hình hệ thống chưa đầy đủ. Vui lòng kiểm tra system_settings.',
            ], 500);
        }

        $borrowDays  = (int) $settings['max_borrow_days'];
        $borrowLimit = (int) $settings['max_books_per_user'];
        $userId      = (int) $request->user_id;
        $copyIds     = $request->copy_ids;
        $today       = now()->toDateString();
        $dueDate     = now()->addDays($borrowDays)->toDateString();

        /** @var \App\Models\User $authUser */
        $authUser    = $request->user();
        $librarianId = $authUser->getKey();

        // [2] Validate độc giả — 1 query
        $reader = DB::table('users as u')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'u.user_id')
            ->where('u.user_id', $userId)
            ->select(
                'u.user_id', 'u.full_name',
                'lc.card_id', 'lc.card_number',
                'lc.status as card_status', 'lc.expiry_date'
            )
            ->first();

        if (is_null($reader?->card_id)) {
            return response()->json([
                'code'    => 422,
                'message' => 'Độc giả chưa có thẻ thư viện.',
            ], 422);
        }

        if ((int) $reader->card_status === 0) {
            return response()->json([
                'code'    => 422,
                'message' => 'Thẻ thư viện đã bị khóa.',
            ], 422);
        }

        if ($reader->expiry_date < $today) {
            return response()->json([
                'code'    => 422,
                'message' => 'Thẻ thư viện đã hết hạn.',
            ], 422);
        }

        // [3] Đếm số sách đang mượn — 1 query
        $borrowingCount = DB::table('borrow_details as bd')
            ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
            ->where('bt.user_id', $userId)
            ->whereNull('bd.return_date')
            ->count();

        if (($borrowingCount + count($copyIds)) > $borrowLimit) {
            return response()->json([
                'code'    => 422,
                'message' => "Vượt hạn mức mượn: đang mượn {$borrowingCount}, giới hạn {$borrowLimit} quyển.",
            ], 422);
        }

        // [4] Kiểm tra phí chưa thanh toán — 1 query
        $unpaidFines = (float) DB::table('fines')
            ->where('user_id', $userId)
            ->where('status', 'unpaid')
            ->sum('amount');

        if ($unpaidFines > 0) {
            return response()->json([
                'code'    => 422,
                'message' => 'Độc giả còn phí chưa thanh toán: ' . number_format($unpaidFines, 0, ',', '.') . ' VND.',
            ], 422);
        }

        // [5] Validate bản sao trước transaction — 2 queries
        $copies = DB::table('book_copies')
            ->whereIn('copy_id', $copyIds)
            ->select('copy_id', 'barcode', 'status')
            ->get();

        $notAvailable = $copies->filter(fn($c) => $c->status !== 'available');
        if ($notAvailable->isNotEmpty()) {
            $barcodes = $notAvailable->pluck('barcode')->implode(', ');
            return response()->json([
                'code'    => 422,
                'message' => "Bản sao không khả dụng: {$barcodes}.",
            ], 422);
        }

        $activeCopyIds = DB::table('borrow_details')
            ->whereIn('copy_id', $copyIds)
            ->whereNull('return_date')
            ->pluck('copy_id');

        if ($activeCopyIds->isNotEmpty()) {
            $barcodes = $copies->whereIn('copy_id', $activeCopyIds->all())
                ->pluck('barcode')->implode(', ');
            return response()->json([
                'code'    => 422,
                'message' => "Bản sao đang có giao dịch chưa trả: {$barcodes}.",
            ], 422);
        }

        // [6] DB::transaction() — 5 queries
        $borrowId = null;

        try {
            DB::transaction(function () use (
                $copyIds, $userId, $librarianId, $today, $dueDate, &$borrowId
            ) {
                // [6a] Lock toàn bộ tập bản sao + re-validate — 2 queries
                // lockForUpdate() → SELECT ... FOR UPDATE (InnoDB row-level lock)
                $lockedCopies = DB::table('book_copies')
                    ->whereIn('copy_id', $copyIds)
                    ->lockForUpdate()
                    ->get(['copy_id', 'barcode', 'status']);

                $notAvailableAfterLock = $lockedCopies->filter(fn($c) => $c->status !== 'available');
                if ($notAvailableAfterLock->isNotEmpty()) {
                    $barcodes = $notAvailableAfterLock->pluck('barcode')->implode(', ');
                    throw new \RuntimeException('CONFLICT:Bản sao không còn khả dụng: ' . $barcodes . '.');
                }

                $stillActive = DB::table('borrow_details')
                    ->whereIn('copy_id', $copyIds)
                    ->whereNull('return_date')
                    ->pluck('copy_id');

                if ($stillActive->isNotEmpty()) {
                    $barcodes = $lockedCopies->whereIn('copy_id', $stillActive->all())
                        ->pluck('barcode')->implode(', ');
                    throw new \RuntimeException('CONFLICT:Bản sao đang có giao dịch chưa trả: ' . $barcodes . '.');
                }

                // [6b] INSERT borrow_transactions — 1 query
                $borrowId = DB::table('borrow_transactions')->insertGetId([
                    'user_id'      => $userId,
                    'librarian_id' => $librarianId,
                    'borrow_date'  => $today,
                    'due_date'     => $dueDate,
                    'status'       => 'borrowing',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);

                // [6c] Bulk INSERT borrow_details — 1 query
                $rows = array_map(fn($id) => [
                    'borrow_id' => $borrowId,
                    'copy_id'   => $id,
                ], $copyIds);
                DB::table('borrow_details')->insert($rows);

                // [6d] Bulk UPDATE book_copies — 1 query
                DB::table('book_copies')
                    ->whereIn('copy_id', $copyIds)
                    ->update([
                        'status'     => 'borrowed',
                        'updated_at' => now(),
                    ]);
            });
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'CONFLICT:')) {
                return response()->json([
                    'code'    => 409,
                    'message' => substr($msg, 9),
                ], 409);
            }
            throw $e;
        }

        // [7] Build response — 1 query (join book titles)
        $books = DB::table('book_copies as bc')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->whereIn('bc.copy_id', $copyIds)
            ->select('bc.copy_id', 'bc.barcode', 'b.title')
            ->get();

        return response()->json([
            'code'    => 201,
            'message' => 'Tạo phiếu mượn thành công.',
            'results' => [
                'object' => [
                    'borrow_id'      => $borrowId,
                    'borrow_date'    => $today,
                    'due_date'       => $dueDate,
                    'status'         => 'borrowing',
                    'reader'         => [
                        'user_id'     => $reader->user_id,
                        'full_name'   => $reader->full_name,
                        'card_number' => $reader->card_number,
                    ],
                    'librarian_name' => $authUser->full_name,
                    'books'          => $books->map(fn($b) => [
                        'copy_id' => $b->copy_id,
                        'barcode' => $b->barcode,
                        'title'   => $b->title,
                    ])->values()->all(),
                ],
            ],
        ], 201);
    }
}
