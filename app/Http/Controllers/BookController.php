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
