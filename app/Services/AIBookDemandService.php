<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AIBookDemandService
{
    private const CACHE_TTL = 3600; // 1 hour

    // -------------------------------------------------------------------------
    // Feature 1: Gợi ý nhập thêm sách (Gap Analysis)
    // -------------------------------------------------------------------------

    public function getImportSuggestions(): array
    {
        return Cache::remember('ai:import_suggestions', self::CACHE_TTL, function () {
            $fromSearchLogs = $this->getMissingFromSearchLogs();
            $fromWishlists  = $this->getUnstockedWishlistBooks();

            // Merge, deduplicate by lowercased keyword, highest count wins
            $merged = [];
            foreach (array_merge($fromSearchLogs, $fromWishlists) as $item) {
                $key = mb_strtolower(trim($item['keyword']));
                if (!isset($merged[$key]) || $item['search_count'] > $merged[$key]['search_count']) {
                    $merged[$key] = $item;
                }
            }

            usort($merged, fn($a, $b) => $b['search_count'] <=> $a['search_count']);

            return array_values(array_slice($merged, 0, 30));
        });
    }

    private function getMissingFromSearchLogs(): array
    {
        $rows = DB::table('search_logs')
            ->select('keyword', DB::raw('COUNT(*) as search_count'))
            ->where('result_count', 0)
            ->where('searched_at', '>=', now()->subMonths(6))
            ->groupBy('keyword')
            ->orderByDesc('search_count')
            ->limit(50)
            ->get();

        return $rows->map(function ($row) {
            return [
                'keyword'      => $row->keyword,
                'search_count' => (int) $row->search_count,
                'source'       => 'search_log',
                'suggestion'   => "Nên nhập sách về chủ đề \"{$row->keyword}\" ({$row->search_count} lượt tìm không có kết quả)",
            ];
        })->all();
    }

    private function getUnstockedWishlistBooks(): array
    {
        $rows = DB::table('wishlists as w')
            ->join('books as b', 'b.book_id', '=', 'w.book_id')
            ->select(
                'b.book_id',
                'b.title as keyword',
                DB::raw('COUNT(DISTINCT w.user_id) as search_count')
            )
            ->where('w.list_type', 'want_to_read')
            ->whereRaw('(SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = b.book_id AND bc.status = \'available\') = 0')
            ->groupBy('b.book_id', 'b.title')
            ->orderByDesc('search_count')
            ->limit(30)
            ->get();

        return $rows->map(function ($row) {
            return [
                'book_id'      => (int) $row->book_id,
                'keyword'      => $row->keyword,
                'search_count' => (int) $row->search_count,
                'source'       => 'wishlist',
                'suggestion'   => "Sách \"{$row->keyword}\" có {$row->search_count} độc giả muốn đọc nhưng không còn bản nào — cần nhập thêm",
            ];
        })->all();
    }

    // -------------------------------------------------------------------------
    // Feature 2: Phát hiện sách ít được mượn
    // -------------------------------------------------------------------------

    public function getLowBorrowBooks(): array
    {
        return Cache::remember('ai:low_borrow_books', self::CACHE_TTL, function () {
            $cutoff = now()->subMonths(12)->toDateString();

            $rows = DB::table('books as b')
                ->leftJoin('book_categories as bc_cat', 'bc_cat.book_id', '=', 'b.book_id')
                ->leftJoin('categories as c', 'c.category_id', '=', 'bc_cat.category_id')
                ->leftJoin('book_copies as bc', 'bc.book_id', '=', 'b.book_id')
                ->leftJoin('borrow_details as bd', 'bd.copy_id', '=', 'bc.copy_id')
                ->leftJoin('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
                ->select(
                    'b.book_id',
                    'b.title as book_name',
                    DB::raw('MIN(c.category_name) as category'),
                    DB::raw('MAX(bt.borrow_date) as last_borrow_date'),
                    DB::raw('COUNT(DISTINCT bd.copy_id) as total_borrows')
                )
                ->groupBy('b.book_id', 'b.title')
                ->havingRaw('MAX(bt.borrow_date) < ? OR MAX(bt.borrow_date) IS NULL', [$cutoff])
                ->orderByRaw('MAX(bt.borrow_date) IS NOT NULL ASC, MAX(bt.borrow_date) ASC')
                ->limit(100)
                ->get();

            return $rows->map(function ($row) {
                $lastDate   = $row->last_borrow_date;
                $suggestion = $this->determineSuggestion($lastDate);

                return [
                    'book_name'        => $row->book_name,
                    'category'         => $row->category ?? 'Chưa phân loại',
                    'last_borrow_date' => $lastDate,
                    'suggestion'       => $suggestion,
                ];
            })->all();
        });
    }

    private function determineSuggestion(?string $lastBorrowDate): string
    {
        if ($lastBorrowDate === null) {
            return 'Thanh lý';
        }

        $monthsAgo = now()->diffInMonths($lastBorrowDate);

        if ($monthsAgo >= 24) {
            return 'Thanh lý';
        }

        return 'Chuyển kho';
    }

    // -------------------------------------------------------------------------
    // Feature 3: Dự báo nhu cầu theo mùa
    // -------------------------------------------------------------------------

    public function getSeasonalDemand(int $year): array
    {
        $currentYear = now()->year;

        if ($year > $currentYear) {
            $cacheKey = "ai:seasonal_demand:forecast:{$year}";

            return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($currentYear) {
                $thisYearData = $this->getHistoricalDemandData($currentYear);
                $lastYearData = $this->getHistoricalDemandData($currentYear - 1);

                $mappedThisYear = [];
                foreach ($thisYearData as $item) {
                    $key = $item['month'] . '-' . $item['category'];
                    $mappedThisYear[$key] = $item['borrow_count'];
                }

                $mappedLastYear = [];
                foreach ($lastYearData as $item) {
                    $key = $item['month'] . '-' . $item['category'];
                    $mappedLastYear[$key] = $item['borrow_count'];
                }

                $allKeys = array_unique(array_merge(array_keys($mappedThisYear), array_keys($mappedLastYear)));
                $forecast = [];

                foreach ($allKeys as $key) {
                    list($month, $category) = explode('-', $key);
                    $thisYearCount = $mappedThisYear[$key] ?? 0;
                    $lastYearCount = $mappedLastYear[$key] ?? 0;

                    // Thuật toán san bằng mũ đơn giản (SES):
                    $forecastCount = (int) round(0.6 * $thisYearCount + 0.4 * $lastYearCount);

                    if ($forecastCount > 0) {
                        $forecast[] = [
                            'month'        => (int) $month,
                            'category'     => $category,
                            'borrow_count' => $forecastCount,
                        ];
                    }
                }

                usort($forecast, function ($a, $b) {
                    if ($a['month'] === $b['month']) {
                        return $b['borrow_count'] <=> $a['borrow_count'];
                    }
                    return $a['month'] <=> $b['month'];
                });

                return $forecast;
            });
        }

        $cacheKey = "ai:seasonal_demand:{$year}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($year) {
            return $this->getHistoricalDemandData($year);
        });
    }

    private function getHistoricalDemandData(int $year): array
    {
        $rows = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->join('book_categories as bc_cat', 'bc_cat.book_id', '=', 'b.book_id')
            ->join('categories as c', 'c.category_id', '=', 'bc_cat.category_id')
            ->select(
                DB::raw('MONTH(bt.borrow_date) as month'),
                'c.category_name as category',
                DB::raw('COUNT(DISTINCT bd.copy_id) as borrow_count')
            )
            ->whereYear('bt.borrow_date', $year)
            ->whereNotNull('c.category_name')
            ->groupBy(
                DB::raw('MONTH(bt.borrow_date)'),
                'c.category_id',
                'c.category_name'
            )
            ->get();

        return $rows->map(fn($row) => [
            'month'        => (int) $row->month,
            'category'     => $row->category,
            'borrow_count' => (int) $row->borrow_count,
        ])->all();
    }

    // -------------------------------------------------------------------------
    // Cache invalidation (called by scheduler)
    // -------------------------------------------------------------------------

    public function clearCache(?int $year = null): void
    {
        Cache::forget('ai:import_suggestions');
        Cache::forget('ai:low_borrow_books');

        $targetYear = $year ?? now()->year;
        Cache::forget("ai:seasonal_demand:{$targetYear}");
        Cache::forget('ai:seasonal_demand:' . ($targetYear - 1));
    }
}
