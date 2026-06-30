<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class BookService
{
    public function __construct()
    {
    }

    private function normalizeSearch(string $str): string
    {
        $lower = mb_strtolower($str);
        $nfd   = normalizer_normalize($lower, \Normalizer::NFD);
        $s     = is_string($nfd) ? preg_replace('/\p{Mn}/u', '', $nfd) : $lower;
        return str_replace('đ', 'd', $s ?? $lower);
    }

    private function viNormSql(string $col): string
    {
        $map = [
            'à' => 'a', 'á' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ắ' => 'a', 'ằ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ấ' => 'a', 'ầ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'è' => 'e', 'é' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ế' => 'e', 'ề' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'ì' => 'i', 'í' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ố' => 'o', 'ồ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ớ' => 'o', 'ờ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ứ' => 'u', 'ừ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ỳ' => 'y', 'ý' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
            'đ' => 'd',
        ];
        $expr = "LOWER($col)";
        foreach ($map as $from => $to) {
            $expr = "REPLACE($expr, '$from', '$to')";
        }
        return $expr;
    }

    public function searchBooks(
        string $query,
        bool $availableOnly = false,
        int $limit = 10,
        array $keywords = [],
        string $language = ''
    ): array {
        $titleNorm     = $this->viNormSql('b.title');
        $authorNorm    = $this->viNormSql('a.author_name');
        $descNorm      = $this->viNormSql('b.description');
        $categoryNorm  = $this->viNormSql('c2.category_name');
        // keywords[] takes priority; fall back to single query string
        $terms = !empty($keywords)
            ? array_values(array_filter(array_map('trim', $keywords)))
            : (trim($query) !== '' ? [trim($query)] : []);

        // Relevance score uses separate subquery aliases (a3/c3/p3) to avoid
        // conflicts with the main JOIN aliases (a/c2/p2) in the WHERE clause.
        [$scoreExpr, $scoreBindings] = $this->buildRelevanceScore(
            $terms,
            $titleNorm,
            $this->viNormSql('a3.author_name'),
            $this->viNormSql('c3.category_name'),
            $descNorm
        );

        $books = DB::table('books as b')
            ->leftJoin('book_authors as ba', 'b.book_id', '=', 'ba.book_id')
            ->leftJoin('authors as a', 'ba.author_id', '=', 'a.author_id')
            ->when(!empty($terms), function ($q) use ($terms, $titleNorm, $authorNorm, $descNorm, $categoryNorm) {
                // OR between terms — each term is independently matched across all fields
                $q->where(function ($outer) use ($terms, $titleNorm, $authorNorm, $descNorm, $categoryNorm) {
                    foreach ($terms as $term) {
                        $like = '%' . $this->normalizeSearch($term) . '%';
                        $outer->orWhere(function ($sub) use ($like, $titleNorm, $authorNorm, $descNorm, $categoryNorm) {
                            $sub->whereRaw("$titleNorm LIKE ?", [$like])
                                ->orWhereRaw("$authorNorm LIKE ?", [$like])
                                ->orWhereRaw('b.isbn LIKE ?', [$like])
                                ->orWhereRaw("$descNorm LIKE ?", [$like])
                                ->orWhereRaw(
                                    "EXISTS (SELECT 1 FROM book_categories bc2 JOIN categories c2 ON bc2.category_id = c2.category_id WHERE bc2.book_id = b.book_id AND $categoryNorm LIKE ?)",
                                    [$like]
                                );
                        });
                    }
                });
            })
            ->when($availableOnly, function ($q) {
                $q->whereRaw(
                    "(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = 'available') > 0"
                );
            })
            ->when($language !== '', function ($q) use ($language) {
                $q->where('b.language', $language);
            })
            ->select(
                'b.book_id',
                'b.title',
                DB::raw('GROUP_CONCAT(DISTINCT a.author_name ORDER BY a.author_name SEPARATOR ", ") as author'),
                'b.isbn',
                DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
            )
            ->groupBy('b.book_id', 'b.title', 'b.isbn')
            ->orderByRaw("$scoreExpr DESC", $scoreBindings)
            ->orderBy('b.title')
            ->limit($limit)
            ->get();

        return $books->map(fn ($b) => [
            'book_id'          => (int) $b->book_id,
            'title'            => $b->title,
            'author'           => $b->author,
            'isbn'             => $b->isbn,
            'available_copies' => (int) $b->available_copies,
        ])->values()->toArray();
    }

    /**
     * Build a SQL relevance score expression for ranking search results.
     *
     * Weights: title=60, author=40, category=30, description=10, isbn=5.
     * Scores are summed across all search terms so books matching more terms rank higher.
     *
     * Returns [$sqlExpression, $bindings] ready for ->orderByRaw().
     * When $terms is empty, returns ['0', []] — effectively no-op, falls through to title sort.
     */
    private function buildRelevanceScore(
        array $terms,
        string $titleNorm,
        string $authorNorm,
        string $categoryNorm,
        string $descNorm
    ): array {
        if (empty($terms)) {
            return ['0', []];
        }

        $parts    = [];
        $bindings = [];

        foreach ($terms as $term) {
            $normTerm = $this->normalizeSearch($term);
            $like     = '%' . $normTerm . '%';

            // Exact title match=120, partial LIKE=60 — specific queries rank before partial matches
            $parts[]    = "CASE WHEN $titleNorm = ? THEN 120 WHEN $titleNorm LIKE ? THEN 60 ELSE 0 END";
            $bindings[] = $normTerm;
            $bindings[] = $like;

            $parts[]    = "CASE WHEN EXISTS(SELECT 1 FROM book_authors ba3 JOIN authors a3 ON ba3.author_id = a3.author_id WHERE ba3.book_id = b.book_id AND $authorNorm LIKE ?) THEN 40 ELSE 0 END";
            $bindings[] = $like;

            $parts[]    = "CASE WHEN EXISTS(SELECT 1 FROM book_categories bc3 JOIN categories c3 ON bc3.category_id = c3.category_id WHERE bc3.book_id = b.book_id AND $categoryNorm LIKE ?) THEN 30 ELSE 0 END";
            $bindings[] = $like;

            $parts[]    = "CASE WHEN $descNorm LIKE ? THEN 10 ELSE 0 END";
            $bindings[] = $like;

            $parts[]    = "CASE WHEN b.isbn LIKE ? THEN 5 ELSE 0 END";
            $bindings[] = $like;
        }

        return ['(' . implode(' + ', $parts) . ')', $bindings];
    }

    public function getBookDetail(int $bookId): ?array
    {
        $book = DB::table('books as b')
            ->leftJoin('publishers as p', 'b.publisher_id', '=', 'p.publisher_id')
            ->where('b.book_id', $bookId)
            ->select(
                'b.book_id', 'b.title', 'b.isbn', 'b.description',
                'b.publish_year', 'b.language', 'b.pages', 'b.avg_rating', 'b.total_reviews',
                'p.name as publisher',
                DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
            )
            ->first();

        if (!$book) return null;

        $authors = DB::table('authors as a')
            ->join('book_authors as ba', 'a.author_id', '=', 'ba.author_id')
            ->where('ba.book_id', $bookId)
            ->pluck('a.author_name')
            ->values()
            ->toArray();

        $categories = DB::table('categories as c')
            ->join('book_categories as bc', 'c.category_id', '=', 'bc.category_id')
            ->where('bc.book_id', $bookId)
            ->pluck('c.category_name')
            ->values()
            ->toArray();

        return [
            'book_id'          => (int) $book->book_id,
            'title'            => $book->title,
            'isbn'             => $book->isbn,
            'description'      => $book->description,
            'publish_year'     => $book->publish_year,
            'language'         => $book->language,
            'pages'            => $book->pages,
            'avg_rating'       => (float) $book->avg_rating,
            'total_reviews'    => (int) $book->total_reviews,
            'publisher'        => $book->publisher,
            'available_copies' => (int) $book->available_copies,
            'authors'          => $authors,
            'categories'       => $categories,
        ];
    }

    public function createReservation(int $userId, int $bookId): array
    {
        return DB::transaction(function () use ($userId, $bookId) {
            $book = DB::table('books')
                ->where('book_id', $bookId)
                ->lockForUpdate()
                ->first();

            if (!$book) {
                return [
                    'success' => false,
                    'error'   => 'book_not_found',
                    'message' => "Không tìm thấy sách với ID {$bookId}.",
                ];
            }

            $card = DB::table('library_cards')->where('user_id', $userId)->first();
            if (!$card) {
                return [
                    'success' => false,
                    'error'   => 'no_card',
                    'message' => 'Bạn chưa có thẻ thư viện. Vui lòng đăng ký thẻ tại quầy thư viện.',
                ];
            }
            if ((int) $card->status === 0) {
                return [
                    'success' => false,
                    'error'   => 'card_locked',
                    'message' => 'Thẻ thư viện của bạn đã bị khóa. Vui lòng liên hệ thủ thư.',
                ];
            }
            if ($card->expiry_date < now()->toDateString()) {
                return [
                    'success' => false,
                    'error'   => 'card_expired',
                    'message' => 'Thẻ thư viện của bạn đã hết hạn. Vui lòng gia hạn thẻ tại quầy.',
                ];
            }

            $availableCopies = DB::table('book_copies')
                ->where('book_id', $bookId)
                ->where('status', 'available')
                ->count();

            if ($availableCopies > 0) {
                return [
                    'success'          => false,
                    'error'            => 'book_available',
                    'title'            => $book->title,
                    'available_copies' => $availableCopies,
                    'message'          => "Sách \"{$book->title}\" hiện còn {$availableCopies} bản có thể mượn trực tiếp tại quầy — không cần đặt trước.",
                ];
            }

            $existing = DB::table('reservations')
                ->where('user_id', $userId)
                ->where('book_id', $bookId)
                ->whereIn('status', ['waiting', 'ready'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return [
                    'success'        => false,
                    'error'          => 'already_reserved',
                    'already_reserved' => true,
                    'title'          => $book->title,
                    'queue_position' => (int) $existing->queue_position,
                    'message'        => "Bạn đã đặt trước sách \"{$book->title}\" (vị trí {$existing->queue_position} trong hàng chờ). Vui lòng chờ thông báo.",
                ];
            }

            $maxPerUser = (int) (DB::table('system_settings')
                ->where('config_key', 'max_reservations_per_user')
                ->value('config_value') ?? 3);

            $activeCount = DB::table('reservations')
                ->where('user_id', $userId)
                ->whereIn('status', ['waiting', 'ready'])
                ->count();

            if ($activeCount >= $maxPerUser) {
                return [
                    'success' => false,
                    'error'   => 'limit_exceeded',
                    'message' => "Bạn đã đặt trước {$activeCount}/{$maxPerUser} cuốn sách — đã đạt giới hạn. Vui lòng hủy bớt trước khi đặt thêm.",
                ];
            }

            $nextPosition = (int) DB::table('reservations')
                ->where('book_id', $bookId)
                ->whereIn('status', ['waiting', 'ready'])
                ->max('queue_position') + 1;

            $reservationId = DB::table('reservations')->insertGetId([
                'user_id'        => $userId,
                'book_id'        => $bookId,
                'queue_position' => $nextPosition,
                'status'         => 'waiting',
                'notified_at'    => null,
                'expired_at'     => null,
                'created_at'     => now(),
            ]);

            $inserted = DB::table('reservations')
                ->where('reservation_id', $reservationId)
                ->first();

            return [
                'success'        => true,
                'reservation_id' => (int) $inserted->reservation_id,
                'book_id'        => (int) $inserted->book_id,
                'title'          => $book->title,
                'queue_position' => (int) $inserted->queue_position,
                'status'         => $inserted->status,
                'message'        => "Đặt trước sách \"{$book->title}\" thành công! Bạn đang ở vị trí {$inserted->queue_position} trong hàng chờ. Chúng tôi sẽ thông báo khi sách sẵn sàng.",
            ];
        });
    }

    public function getBookAvailability(int $bookId): ?array
    {
        $book = DB::table('books as b')
            ->where('b.book_id', $bookId)
            ->select('b.book_id', 'b.title')
            ->first();

        if (!$book) return null;

        $counts = DB::table('book_copies')
            ->where('book_id', $bookId)
            ->selectRaw("
                COUNT(*) as total_copies,
                SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_copies,
                SUM(CASE WHEN status = 'borrowed'  THEN 1 ELSE 0 END) as borrowed_copies,
                SUM(CASE WHEN status = 'reserved'  THEN 1 ELSE 0 END) as reserved_copies
            ")
            ->first();

        $available = (int) ($counts->available_copies ?? 0);
        $total     = (int) ($counts->total_copies     ?? 0);
        $borrowed  = (int) ($counts->borrowed_copies  ?? 0);
        $reserved  = (int) ($counts->reserved_copies  ?? 0);

        return [
            'book_id'          => (int) $book->book_id,
            'title'            => $book->title,
            'total_copies'     => $total,
            'available_copies' => $available,
            'borrowed_copies'  => $borrowed,
            'reserved_copies'  => $reserved,
            'is_available'     => $available > 0,
        ];
    }

    public function getLibrarySettings(): array
    {
        $settings = DB::table('system_settings')
            ->whereIn('config_key', ['max_books_per_user', 'max_borrow_days', 'fine_per_day', 'card_validity_months'])
            ->pluck('config_value', 'config_key');

        $result = [
            'borrow_limit'    => (int) ($settings['max_books_per_user'] ?? 5),
            'max_borrow_days' => (int) ($settings['max_borrow_days'] ?? 14),
            'fine_per_day'    => (int) ($settings['fine_per_day'] ?? 2000),
        ];

        if ($settings->get('card_validity_months') !== null) {
            $result['card_validity_months'] = (int) $settings->get('card_validity_months');
        }

        return $result;
    }
}
