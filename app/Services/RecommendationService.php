<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class RecommendationService
{
    private const BORROW_WEIGHT = 3;

    private const WISHLIST_WEIGHTS = [
        'read'         => 3,
        'reading'      => 2,
        'want_to_read' => 1,
        'favorite'     => 1,
    ];

    private const SCORE_AUTHOR   = 5;
    private const SCORE_CATEGORY = 3;
    private const LIMIT          = 10;

    public function forUser(int $userId): array
    {
        $sourceBooks = $this->collectSourceBooks($userId);

        if ($sourceBooks->isEmpty()) {
            return [];
        }

        $authorWeights   = $this->buildProfile($sourceBooks, 'book_authors',   'author_id');
        $categoryWeights = $this->buildProfile($sourceBooks, 'book_categories', 'category_id');

        if (empty($authorWeights) && empty($categoryWeights)) {
            return [];
        }

        $excludedIds = $sourceBooks->pluck('book_id')->toArray();
        $candidates  = $this->fetchCandidates(
            array_keys($authorWeights),
            array_keys($categoryWeights),
            $excludedIds
        );

        if ($candidates->isEmpty()) {
            return [];
        }

        return $this->score($candidates, $authorWeights, $categoryWeights);
    }

    // Returns collection of {book_id, weight} — weight accumulated across all interaction sources
    private function collectSourceBooks(int $userId): Collection
    {
        $map = [];

        // Borrow history: each unique book counted once at weight 3
        DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc',    'bc.copy_id',   '=', 'bd.copy_id')
            ->where('bt.user_id', $userId)
            ->distinct()
            ->pluck('bc.book_id')
            ->each(function ($bookId) use (&$map) {
                $map[$bookId] = ($map[$bookId] ?? 0) + self::BORROW_WEIGHT;
            });

        // Wishlist interactions
        DB::table('wishlists')
            ->where('user_id', $userId)
            ->whereIn('list_type', array_keys(self::WISHLIST_WEIGHTS))
            ->select('book_id', 'list_type')
            ->get()
            ->each(function ($row) use (&$map) {
                $w = self::WISHLIST_WEIGHTS[$row->list_type] ?? 1;
                $map[$row->book_id] = ($map[$row->book_id] ?? 0) + $w;
            });

        $result = collect();
        foreach ($map as $bookId => $weight) {
            $result->push((object) ['book_id' => $bookId, 'weight' => $weight]);
        }
        return $result;
    }

    // Builds a [entity_id => accumulated_weight] map from a pivot table
    private function buildProfile(Collection $sourceBooks, string $pivot, string $idColumn): array
    {
        $bookIds = $sourceBooks->pluck('book_id')->toArray();
        if (empty($bookIds)) {
            return [];
        }

        $weightByBook = $sourceBooks->keyBy('book_id');
        $profile      = [];

        DB::table($pivot)
            ->whereIn('book_id', $bookIds)
            ->select('book_id', $idColumn)
            ->get()
            ->each(function ($row) use (&$profile, $weightByBook, $idColumn) {
                $w = (int) ($weightByBook->get($row->book_id)->weight ?? 0);
                $profile[$row->$idColumn] = ($profile[$row->$idColumn] ?? 0) + $w;
            });

        return $profile;
    }

    private function fetchCandidates(array $authorIds, array $categoryIds, array $excludedIds): Collection
    {
        $query = DB::table('books as b')
            ->select(
                'b.book_id',
                'b.title',
                'b.cover_image',
                'b.avg_rating',
                DB::raw('(SELECT COUNT(*) FROM book_copies WHERE book_id = b.book_id AND status = "available") as available_copies'),
                DB::raw("(SELECT GROUP_CONCAT(a.author_name ORDER BY a.author_name SEPARATOR ', ')
                          FROM book_authors ba
                          JOIN authors a ON a.author_id = ba.author_id
                          WHERE ba.book_id = b.book_id) as author_name")
            );

        if (!empty($excludedIds)) {
            $query->whereNotIn('b.book_id', $excludedIds);
        }

        // Book must match at least one known author or one known category
        $query->where(function ($q) use ($authorIds, $categoryIds) {
            if (!empty($authorIds)) {
                $q->orWhereExists(function ($sub) use ($authorIds) {
                    $sub->select(DB::raw(1))
                        ->from('book_authors as ba2')
                        ->whereColumn('ba2.book_id', 'b.book_id')
                        ->whereIn('ba2.author_id', $authorIds);
                });
            }
            if (!empty($categoryIds)) {
                $q->orWhereExists(function ($sub) use ($categoryIds) {
                    $sub->select(DB::raw(1))
                        ->from('book_categories as bc2')
                        ->whereColumn('bc2.book_id', 'b.book_id')
                        ->whereIn('bc2.category_id', $categoryIds);
                });
            }
        });

        $books = $query->get();

        if ($books->isEmpty()) {
            return $books;
        }

        // Batch-load pivot data for scoring (avoids N+1)
        $bookIds = $books->pluck('book_id')->toArray();

        $bookAuthors = DB::table('book_authors')
            ->whereIn('book_id', $bookIds)
            ->get()
            ->groupBy('book_id');

        $bookCategories = DB::table('book_categories')
            ->whereIn('book_id', $bookIds)
            ->get()
            ->groupBy('book_id');

        return $books->map(function ($book) use ($bookAuthors, $bookCategories) {
            $book->author_ids   = ($bookAuthors->get($book->book_id)   ?? collect())->pluck('author_id')->toArray();
            $book->category_ids = ($bookCategories->get($book->book_id) ?? collect())->pluck('category_id')->toArray();
            return $book;
        });
    }

    private function score(Collection $candidates, array $authorWeights, array $categoryWeights): array
    {
        return $candidates
            ->map(function ($book) use ($authorWeights, $categoryWeights) {
                $score = 0;

                foreach ($book->author_ids as $aid) {
                    if (isset($authorWeights[$aid])) {
                        $score += self::SCORE_AUTHOR * $authorWeights[$aid];
                    }
                }

                foreach ($book->category_ids as $cid) {
                    if (isset($categoryWeights[$cid])) {
                        $score += self::SCORE_CATEGORY * $categoryWeights[$cid];
                    }
                }

                return [
                    'book_id'          => $book->book_id,
                    'title'            => $book->title,
                    'author_name'      => $book->author_name,
                    'cover_image'      => $book->cover_image,
                    'avg_rating'       => (float) $book->avg_rating,
                    'available_copies' => (int) $book->available_copies,
                    'score'            => $score,
                ];
            })
            ->sortByDesc('score')
            ->take(self::LIMIT)
            ->values()
            ->toArray();
    }
}
