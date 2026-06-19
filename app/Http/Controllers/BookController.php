<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BookController extends Controller
{
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

    public function search(Request $request)
    {
        $q           = trim($request->query('q', ''));
        $like        = '%' . $this->normalizeSearch($q) . '%';
        $categoryId  = $request->query('category_id')  ? (int) $request->query('category_id')  : null;
        $authorId    = $request->query('author_id')    ? (int) $request->query('author_id')    : null;
        $publisherId = $request->query('publisher_id') ? (int) $request->query('publisher_id') : null;
        $language    = $request->query('language');
        $yearFrom    = $request->query('year_from')    ? (int) $request->query('year_from')    : null;
        $yearTo      = $request->query('year_to')      ? (int) $request->query('year_to')      : null;
        $availableOnly = $request->query('available_only') === '1';

        $titleNorm  = $this->viNormSql('b.title');
        $authorNorm = $this->viNormSql('a.author_name');
        $descNorm   = $this->viNormSql('b.description');

        $books = DB::table('books as b')
            ->leftJoin('book_authors as ba', 'b.book_id', '=', 'ba.book_id')
            ->leftJoin('authors as a', 'ba.author_id', '=', 'a.author_id')
            // full-text search (diacritic-insensitive)
            ->when($q !== '', function ($query) use ($like, $titleNorm, $authorNorm, $descNorm) {
                $query->where(function ($sub) use ($like, $titleNorm, $authorNorm, $descNorm) {
                    $sub->whereRaw("$titleNorm LIKE ?", [$like])
                        ->orWhereRaw("$authorNorm LIKE ?", [$like])
                        ->orWhereRaw('b.isbn LIKE ?', [$like])
                        ->orWhereRaw("$descNorm LIKE ?", [$like]);
                });
            })
            // category filter — WHERE EXISTS avoids duplicate rows from many-to-many
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->whereExists(function ($sub) use ($categoryId) {
                    $sub->select(DB::raw(1))
                        ->from('book_categories')
                        ->whereColumn('book_categories.book_id', 'b.book_id')
                        ->where('book_categories.category_id', $categoryId);
                });
            })
            // author filter — WHERE EXISTS avoids duplicate rows from many-to-many
            ->when($authorId, function ($query) use ($authorId) {
                $query->whereExists(function ($sub) use ($authorId) {
                    $sub->select(DB::raw(1))
                        ->from('book_authors')
                        ->whereColumn('book_authors.book_id', 'b.book_id')
                        ->where('book_authors.author_id', $authorId);
                });
            })
            ->when($publisherId, function ($query) use ($publisherId) {
                $query->where('b.publisher_id', $publisherId);
            })
            ->when($language, function ($query) use ($language) {
                $query->where('b.language', $language);
            })
            ->when($yearFrom, function ($query) use ($yearFrom) {
                $query->where('b.publish_year', '>=', $yearFrom);
            })
            ->when($yearTo, function ($query) use ($yearTo) {
                $query->where('b.publish_year', '<=', $yearTo);
            })
            // available_only: correlated subquery in WHERE — HAVING cannot reference aliased subquery
            ->when($availableOnly, function ($query) {
                $query->whereRaw(
                    "(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = 'available') > 0"
                );
            })
            ->select(
                'b.book_id',
                'b.title',
                DB::raw('GROUP_CONCAT(DISTINCT a.author_name ORDER BY a.author_name SEPARATOR ", ") as author'),
                'b.isbn',
                'b.cover_image',
                DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
            )
            ->groupBy('b.book_id', 'b.title', 'b.isbn', 'b.cover_image')
            ->orderBy('b.title')
            ->limit(50)
            ->get();

        return response()->json($books);
    }

    public function home()
    {
        $featured = DB::table('books as b')
            ->where('b.is_featured', 1)
            ->select(
                'b.book_id', 'b.title', 'b.cover_image',
                DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
            )
            ->orderBy('b.title')
            ->limit(10)
            ->get();

        $newBooks = DB::table('books as b')
            ->select(
                'b.book_id', 'b.title', 'b.cover_image',
                DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
            )
            ->orderByDesc('b.created_at')
            ->limit(10)
            ->get();

        $mostBorrowed = DB::table('books as b')
            ->join('book_copies as bc', 'bc.book_id', '=', 'b.book_id')
            ->join('borrow_details as bd', 'bd.copy_id', '=', 'bc.copy_id')
            ->select(
                'b.book_id', 'b.title', 'b.cover_image',
                DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
            )
            ->groupBy('b.book_id', 'b.title', 'b.cover_image')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(10)
            ->get();

        return response()->json([
            'featured'      => $featured->values(),
            'new_books'     => $newBooks->values(),
            'most_borrowed' => $mostBorrowed->values(),
        ]);
    }

    public function reviews(Request $request, int $bookId)
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = 10;
        $offset  = ($page - 1) * $perPage;

        $total = DB::table('reviews')
            ->where('book_id', $bookId)
            ->where('is_hidden', 0)
            ->count();

        $data = DB::table('reviews as r')
            ->join('users as u', 'r.user_id', '=', 'u.user_id')
            ->where('r.book_id', $bookId)
            ->where('r.is_hidden', 0)
            ->select('r.review_id', 'r.user_id', 'u.full_name', 'r.rating', 'r.content', 'r.created_at')
            ->orderByDesc('r.created_at')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return response()->json([
            'data'       => $data->values(),
            'pagination' => [
                'current_page' => $page,
                'last_page'    => (int) ceil($total / $perPage),
                'per_page'     => $perPage,
                'total'        => (int) $total,
            ],
        ]);
    }

    public function reviewPermission(Request $request, int $bookId)
    {
        $userId = (int) $request->query('user_id', 0);

        $canReview = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bt.borrow_id', '=', 'bd.borrow_id')
            ->join('book_copies as bc', 'bd.copy_id', '=', 'bc.copy_id')
            ->where('bt.user_id', $userId)
            ->where('bc.book_id', $bookId)
            ->whereNotNull('bd.return_date')
            ->exists();

        return response()->json(['can_review' => $canReview]);
    }

    public function submitReview(Request $request, int $bookId)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'rating'  => 'required|integer|min:1|max:5',
            'content' => 'required|string|max:1000',
        ]);

        $userId  = (int) $request->input('user_id');
        $rating  = (int) $request->input('rating');
        $content = $request->input('content');

        // Business rule: must have at least one returned borrow for this book
        $hasReturned = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bt.borrow_id', '=', 'bd.borrow_id')
            ->join('book_copies as bc', 'bd.copy_id', '=', 'bc.copy_id')
            ->where('bt.user_id', $userId)
            ->where('bc.book_id', $bookId)
            ->whereNotNull('bd.return_date')
            ->exists();

        if (!$hasReturned) {
            return response()->json([
                'message' => 'Bạn chỉ có thể đánh giá sau khi đã mượn và trả sách này.',
            ], 403);
        }

        $existing = DB::table('reviews')
            ->where('user_id', $userId)
            ->where('book_id', $bookId)
            ->first();

        $now = now();

        if ($existing) {
            DB::table('reviews')
                ->where('review_id', $existing->review_id)
                ->update([
                    'rating'     => $rating,
                    'content'    => $content,
                    'updated_at' => $now,
                ]);
            $statusCode = 200;
        } else {
            DB::table('reviews')->insert([
                'book_id'    => $bookId,
                'user_id'    => $userId,
                'rating'     => $rating,
                'content'    => $content,
                'is_hidden'  => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $statusCode = 201;
        }

        // Recalculate denormalized avg_rating / total_reviews on books
        $stats = DB::table('reviews')
            ->where('book_id', $bookId)
            ->where('is_hidden', 0)
            ->selectRaw('AVG(rating) as avg_r, COUNT(review_id) as total_r')
            ->first();

        DB::table('books')->where('book_id', $bookId)->update([
            'avg_rating'    => round((float) ($stats->avg_r ?? 0), 2),
            'total_reviews' => (int) ($stats->total_r ?? 0),
        ]);

        return response()->json(['message' => 'Đánh giá đã được lưu.'], $statusCode);
    }

    public function show(int $bookId)
    {
        $book = DB::table('books as b')
            ->leftJoin('publishers as p', 'b.publisher_id', '=', 'p.publisher_id')
            ->where('b.book_id', $bookId)
            ->select(
                'b.book_id',
                'b.title',
                'b.isbn',
                'b.cover_image',
                'b.description',
                'b.publish_year',
                'b.language',
                'b.pages',
                'b.avg_rating',
                'b.total_reviews',
                'p.name as publisher',
                DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
            )
            ->first();

        if (!$book) {
            return response()->json(['message' => 'Không tìm thấy sách.'], 404);
        }

        $authors = DB::table('authors as a')
            ->join('book_authors as ba', 'a.author_id', '=', 'ba.author_id')
            ->where('ba.book_id', $bookId)
            ->pluck('a.author_name');

        $categories = DB::table('categories as c')
            ->join('book_categories as bc', 'c.category_id', '=', 'bc.category_id')
            ->where('bc.book_id', $bookId)
            ->pluck('c.category_name');

        return response()->json([
            'book_id'          => $book->book_id,
            'title'            => $book->title,
            'isbn'             => $book->isbn,
            'cover_image'      => $book->cover_image,
            'description'      => $book->description,
            'publish_year'     => $book->publish_year,
            'language'         => $book->language,
            'pages'            => $book->pages,
            'avg_rating'       => (float) $book->avg_rating,
            'total_reviews'    => (int) $book->total_reviews,
            'publisher'        => $book->publisher,
            'available_copies' => (int) $book->available_copies,
            'authors'          => $authors->values(),
            'categories'       => $categories->values(),
        ]);
    }

    public function related(int $bookId)
    {
        $authorIds = DB::table('book_authors')
            ->where('book_id', $bookId)
            ->pluck('author_id');

        $sameAuthor = $authorIds->isEmpty()
            ? collect()
            : DB::table('books as b')
                ->whereExists(function ($sub) use ($authorIds) {
                    $sub->select(DB::raw(1))
                        ->from('book_authors as ba')
                        ->whereColumn('ba.book_id', 'b.book_id')
                        ->whereIn('ba.author_id', $authorIds);
                })
                ->where('b.book_id', '!=', $bookId)
                ->select(
                    'b.book_id',
                    'b.title',
                    'b.cover_image',
                    DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
                )
                ->orderBy('b.title')
                ->limit(6)
                ->get();

        $categoryIds = DB::table('book_categories')
            ->where('book_id', $bookId)
            ->pluck('category_id');

        $sameCategory = $categoryIds->isEmpty()
            ? collect()
            : DB::table('books as b')
                ->whereExists(function ($sub) use ($categoryIds) {
                    $sub->select(DB::raw(1))
                        ->from('book_categories as bc')
                        ->whereColumn('bc.book_id', 'b.book_id')
                        ->whereIn('bc.category_id', $categoryIds);
                })
                ->where('b.book_id', '!=', $bookId)
                ->select(
                    'b.book_id',
                    'b.title',
                    'b.cover_image',
                    DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies')
                )
                ->orderBy('b.title')
                ->limit(6)
                ->get();

        return response()->json([
            'same_author'   => $sameAuthor->values(),
            'same_category' => $sameCategory->values(),
        ]);
    }

    public function filterOptions()
    {
        // Only categories that have at least one book, status active
        $categories = DB::table('categories as c')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('book_categories as bc')
                    ->whereColumn('bc.category_id', 'c.category_id');
            })
            ->where('c.status', 1)
            ->select('c.category_id', 'c.category_name')
            ->orderBy('c.category_name')
            ->get();

        // Only authors that have at least one book
        $authors = DB::table('authors as a')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('book_authors as ba')
                    ->whereColumn('ba.author_id', 'a.author_id');
            })
            ->select('a.author_id', 'a.author_name')
            ->orderBy('a.author_name')
            ->get();

        // Only publishers that have at least one book, status active
        $publishers = DB::table('publishers as p')
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('books as b')
                    ->whereColumn('b.publisher_id', 'p.publisher_id');
            })
            ->where('p.status', 1)
            ->select('p.publisher_id', 'p.name')
            ->orderBy('p.name')
            ->get();

        // Non-null, non-empty languages from books
        $languages = DB::table('books')
            ->select('language')
            ->whereNotNull('language')
            ->where('language', '!=', '')
            ->distinct()
            ->orderBy('language')
            ->pluck('language');

        return response()->json(compact('categories', 'authors', 'publishers', 'languages'));
    }
}
