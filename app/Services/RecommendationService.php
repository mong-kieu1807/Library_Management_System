<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class RecommendationService
{
    // ── M6.1 constants ──────────────────────────────────────────────────────
    private const BORROW_WEIGHT    = 3;
    private const WISHLIST_WEIGHTS = [
        'read'         => 3,
        'reading'      => 2,
        'want_to_read' => 1,
        'favorite'     => 1,
    ];
    private const SCORE_AUTHOR     = 5;
    private const SCORE_CATEGORY   = 3;
    private const LIMIT            = 10;

    // ── M6.2 constants ──────────────────────────────────────────────────────
    private const SIMILAR_USERS_LIMIT = 50;
    private const COLLAB_LIMIT        = 10;

    // =========================================================================
    // M6.1 — Gợi ý theo sở thích / lịch sử mượn
    // =========================================================================

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

    private function collectSourceBooks(int $userId): Collection
    {
        $map = [];

        DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc',    'bc.copy_id',   '=', 'bd.copy_id')
            ->where('bt.user_id', $userId)
            ->distinct()
            ->pluck('bc.book_id')
            ->each(function ($bookId) use (&$map) {
                $map[$bookId] = ($map[$bookId] ?? 0) + self::BORROW_WEIGHT;
            });

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

        $bookIds        = $books->pluck('book_id')->toArray();
        $bookAuthors    = DB::table('book_authors')->whereIn('book_id', $bookIds)->get()->groupBy('book_id');
        $bookCategories = DB::table('book_categories')->whereIn('book_id', $bookIds)->get()->groupBy('book_id');

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

    // =========================================================================
    // M6.2 — Độc giả tương tự cùng mượn (Collaborative Filtering)
    // =========================================================================

    public function collaborativeForUser(int $userId): array
    {
        // Step 1: Get the set of books current user has borrowed
        $borrowedIds = $this->getUserBorrowedBookIds($userId);

        if (empty($borrowedIds)) {
            return [];
        }

        // Steps 2+3: Find other users who share at least 1 borrowed book, ranked by overlap
        $similarUsers = $this->findSimilarUsers($userId, $borrowedIds);

        if ($similarUsers->isEmpty()) {
            return [];
        }

        // Steps 4+5: Collect unread candidates, score, sort, return
        return $this->scoreCandidateBooks($similarUsers, $borrowedIds);
    }

    private function getUserBorrowedBookIds(int $userId): array
    {
        return DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc',    'bc.copy_id',   '=', 'bd.copy_id')
            ->where('bt.user_id', $userId)
            ->distinct()
            ->pluck('bc.book_id')
            ->toArray();
    }

    private function findSimilarUsers(int $userId, array $borrowedIds): Collection
    {
        return DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc',    'bc.copy_id',   '=', 'bd.copy_id')
            ->whereIn('bc.book_id', $borrowedIds)
            ->where('bt.user_id', '!=', $userId)
            ->select('bt.user_id', DB::raw('COUNT(DISTINCT bc.book_id) as overlap'))
            ->groupBy('bt.user_id')
            ->orderByDesc('overlap')
            ->limit(self::SIMILAR_USERS_LIMIT)
            ->get();
    }

    private function scoreCandidateBooks(Collection $similarUsers, array $excludedIds): array
    {
        $overlapMap     = $similarUsers->pluck('overlap', 'user_id')->toArray();
        $similarUserIds = array_keys($overlapMap);

        // All books borrowed by similar users
        $candidateRows = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc',    'bc.copy_id',   '=', 'bd.copy_id')
            ->whereIn('bt.user_id', $similarUserIds)
            ->select('bt.user_id', 'bc.book_id')
            ->distinct()
            ->get();

        // score = (number of similar users who borrowed the book) + (sum of their overlap counts)
        $excludedSet   = array_flip($excludedIds);
        $countByBook   = [];
        $overlapByBook = [];

        foreach ($candidateRows as $row) {
            if (isset($excludedSet[$row->book_id])) {
                continue;
            }
            $countByBook[$row->book_id]   = ($countByBook[$row->book_id]   ?? 0) + 1;
            $overlapByBook[$row->book_id] = ($overlapByBook[$row->book_id] ?? 0) + (int) ($overlapMap[$row->user_id] ?? 0);
        }

        if (empty($countByBook)) {
            return [];
        }

        $scores = [];
        foreach ($countByBook as $bookId => $count) {
            $scores[$bookId] = $count + $overlapByBook[$bookId];
        }

        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, self::COLLAB_LIMIT);

        return $this->fetchCollabBookData($topIds, $scores);
    }

    private function fetchCollabBookData(array $bookIds, array $scores): array
    {
        if (empty($bookIds)) {
            return [];
        }

        $books = DB::table('books as b')
            ->whereIn('b.book_id', $bookIds)
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
            )
            ->get()
            ->keyBy('book_id');

        $result = [];
        foreach ($bookIds as $bookId) {
            $book = $books->get($bookId);
            if (!$book) {
                continue;
            }
            $result[] = [
                'book_id'          => $book->book_id,
                'title'            => $book->title,
                'author_name'      => $book->author_name,
                'cover_image'      => $book->cover_image,
                'avg_rating'       => (float) $book->avg_rating,
                'available_copies' => (int) $book->available_copies,
                'score'            => $scores[$bookId] ?? 0,
            ];
        }
        return $result;
    }
}
