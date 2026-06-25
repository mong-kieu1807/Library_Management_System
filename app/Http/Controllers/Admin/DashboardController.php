<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    // ─── Helpers ──────────────────────────────────────────────────────────

    private function overdueWhere($query)
    {
        return $query
            ->whereNull('bd.return_date')
            ->whereRaw('bt.due_date < CURDATE()');
    }

    // ─── [LEGACY] Full combined endpoint ─────────────────────────────────

    /**
     * GET /private/v1/dashboard
     * Keeps backward compatibility with the existing DashboardPage.tsx.
     */
    public function getDashboardData()
    {
        // -- stats --
        $totalBooks = (int) DB::table('books')->count();

        $activeUsers = (int) DB::table('users')
            ->join('roles', 'roles.role_id', '=', 'users.role_id')
            ->where('roles.role_name', 'reader')
            ->where('users.status', 1)
            ->count();

        $overdueCount = (int) DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->whereNull('bd.return_date')
            ->whereRaw('bt.due_date < CURDATE()')
            ->count();

        $totalBorrowMonth = (int) DB::table('borrow_transactions')
            ->whereMonth('borrow_date', now()->month)
            ->whereYear('borrow_date', now()->year)
            ->count();

        // -- 30-day trend (real data) --
        $today = Carbon::today();
        $trendData = [];
        for ($i = 29; $i >= 0; $i--) {
            $date    = $today->copy()->subDays($i)->format('Y-m-d');
            $borrow  = DB::table('borrow_transactions')->whereDate('borrow_date', $date)->count();
            $return  = DB::table('borrow_details')->whereDate('return_date', $date)->count();
            $trendData[] = ['day' => $date, 'borrow' => $borrow, 'return' => $return];
        }

        // -- inventory --
        $copies     = DB::table('book_copies')
            ->selectRaw("status, COUNT(*) as cnt")
            ->groupBy('status')
            ->pluck('cnt', 'status');
        $reserved   = DB::table('reservations')->whereIn('status', ['waiting', 'ready'])->count();

        $inventoryData = [
            ['name' => 'Có sẵn',      'value' => (int)($copies['available'] ?? 0),    'color' => '#10B981'],
            ['name' => 'Đang mượn',   'value' => (int)($copies['borrowed'] ?? 0),     'color' => '#3B82F6'],
            ['name' => 'Đặt trước',   'value' => (int)$reserved,                       'color' => '#F59E0B'],
            ['name' => 'Bảo dưỡng',   'value' => (int)($copies['maintenance'] ?? 0),  'color' => '#EF4444'],
        ];

        // -- top 5 books --
        $topBooks = DB::table('borrow_details as bd')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('authors as a', 'a.author_id', '=', 'b.author_id')
            ->select('b.title', DB::raw('COALESCE(a.author_name,"") as author'), DB::raw('COUNT(*) as cnt'))
            ->groupBy('b.book_id', 'b.title', 'a.author_name')
            ->orderByDesc('cnt')
            ->limit(5)
            ->get()
            ->values()
            ->map(fn ($r, $i) => ['rank' => $i + 1, 'title' => $r->title, 'author' => $r->author, 'borrows' => (int)$r->cnt]);

        // -- overdue top-5 --
        $overdueList = DB::table('borrow_transactions as bt')
            ->join('users as u', 'u.user_id', '=', 'bt.user_id')
            ->join('borrow_details as bd', function ($j) {
                $j->on('bd.borrow_id', '=', 'bt.borrow_id')->whereNull('bd.return_date');
            })
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('fines as f', function ($j) {
                $j->on('f.borrow_id', '=', 'bt.borrow_id')->on('f.copy_id', '=', 'bd.copy_id');
            })
            ->whereNull('bd.return_date')
            ->whereRaw('bt.due_date < CURDATE()')
            ->select([
                'bt.borrow_id',
                'u.full_name as reader',
                'b.title as book',
                DB::raw('DATEDIFF(CURDATE(), bt.due_date) as days'),
                DB::raw('COALESCE(f.amount, DATEDIFF(CURDATE(), bt.due_date) * (SELECT CAST(config_value AS UNSIGNED) FROM system_settings WHERE config_key="fine_per_day" LIMIT 1)) as fee'),
            ])
            ->orderByDesc('days')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'id'     => 'GD-' . $r->borrow_id,
                'reader' => $r->reader,
                'book'   => $r->book,
                'days'   => (int) $r->days,
                'fee'    => (float) $r->fee,
            ]);

        return response()->json([
            'code'    => 200,
            'results' => [
                'object' => [
                    'stats' => [
                        'totalBooks'        => $totalBooks,
                        'activeUsers'       => $activeUsers,
                        'overdueCount'      => $overdueCount,
                        'totalBorrowMonth'  => $totalBorrowMonth,
                    ],
                    'trendData'     => $trendData,
                    'inventoryData' => $inventoryData,
                    'topBooks'      => $topBooks,
                    'overdueList'   => $overdueList,
                ],
            ],
        ]);
    }

    /**
     * GET /private/v1/dashboard/recent-activities
     */
    public function getRecentActivities(Request $request)
    {
        $page  = max(1, (int) $request->input('page', 1));
        $limit = max(1, (int) $request->input('limit', 5));

        $paginator = DB::table('borrow_transactions as bt')
            ->join('users as u', 'u.user_id', '=', 'bt.user_id')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->select('bd.borrow_id as id', 'u.full_name as userName', 'b.title as courseName', 'bt.borrow_date as date')
            ->orderByDesc('bt.borrow_id')
            ->paginate($limit, ['*'], 'page', $page);

        $rows = collect($paginator->items())->map(fn ($r) => [
            'id'         => (string) $r->id,
            'userName'   => $r->userName,
            'courseName' => $r->courseName,
            'date'       => $r->date,
        ]);

        return response()->json([
            'code'    => 200,
            'results' => [
                'objects' => [
                    'rows'  => $rows,
                    'total' => $paginator->total(),
                    'page'  => $page,
                    'limit' => $limit,
                ],
            ],
        ]);
    }

    // ─── [NEW] Dedicated analytics endpoints ─────────────────────────────

    /**
     * GET /private/v1/dashboard/summary
     * 6 KPI metrics — real-time, no fake fallback.
     */
    public function getSummary()
    {
        $finePerDay = (int) DB::table('system_settings')
            ->where('config_key', 'fine_per_day')->value('config_value') ?: 5000;

        [$totalBooks, $activeBorrows, $overdueUsers, $unpaidFines,
         $totalReservations, $transactionsToday, $totalCopies] = [
            DB::table('books')->count(),
            DB::table('borrow_details')->whereNull('return_date')->count(),
            DB::table('borrow_transactions as bt')
                ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
                ->whereNull('bd.return_date')->whereRaw('bt.due_date < CURDATE()')
                ->distinct()->count('bt.user_id'),
            DB::table('fines')->where('status', 'unpaid')->sum('amount'),
            DB::table('reservations')->whereIn('status', ['waiting', 'ready'])->count(),
            DB::table('borrow_transactions')->whereDate('borrow_date', today())->count(),
            DB::table('book_copies')->count(),
        ];

        // Overdue severity breakdown
        $overdueSeverity = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', function ($j) {
                $j->on('bd.borrow_id', '=', 'bt.borrow_id')->whereNull('bd.return_date');
            })
            ->whereRaw('bt.due_date < CURDATE()')
            ->selectRaw("
                SUM(CASE WHEN DATEDIFF(CURDATE(), bt.due_date) BETWEEN 1 AND 3  THEN 1 ELSE 0 END) as light,
                SUM(CASE WHEN DATEDIFF(CURDATE(), bt.due_date) BETWEEN 4 AND 10 THEN 1 ELSE 0 END) as medium,
                SUM(CASE WHEN DATEDIFF(CURDATE(), bt.due_date) > 10             THEN 1 ELSE 0 END) as heavy
            ")
            ->first();

        // Reservation flow
        $reservationFlow = DB::table('reservations')
            ->selectRaw("status, COUNT(*) as cnt")
            ->groupBy('status')
            ->pluck('cnt', 'status');

        return response()->json([
            'code'    => 200,
            'results' => [
                'object' => [
                    'total_books'          => (int) $totalBooks,
                    'total_copies'         => (int) $totalCopies,
                    'active_borrows'       => (int) $activeBorrows,
                    'overdue_users'        => (int) $overdueUsers,
                    'total_fines_unpaid'   => (int) $unpaidFines,
                    'total_reservations'   => (int) $totalReservations,
                    'transactions_today'   => (int) $transactionsToday,
                    'fine_per_day'         => $finePerDay,
                    'overdue_severity'     => [
                        'light'  => (int)($overdueSeverity->light  ?? 0),
                        'medium' => (int)($overdueSeverity->medium ?? 0),
                        'heavy'  => (int)($overdueSeverity->heavy  ?? 0),
                    ],
                    'reservation_flow'     => [
                        'waiting'   => (int)($reservationFlow['waiting']   ?? 0),
                        'ready'     => (int)($reservationFlow['ready']     ?? 0),
                        'converted' => (int)($reservationFlow['converted'] ?? 0),
                        'expired'   => (int)($reservationFlow['expired']   ?? 0),
                        'cancelled' => (int)($reservationFlow['cancelled'] ?? 0),
                    ],
                ],
            ],
        ]);
    }

    /**
     * GET /private/v1/dashboard/borrows?range=30d|7d|90d
     * Daily borrow + return counts for the requested range.
     */
    public function getBorrowStats(Request $request)
    {
        $range = $request->input('range', '30d');
        $days  = match ($range) {
            '7d'  => 7,
            '90d' => 90,
            default => 30,
        };

        $today  = Carbon::today();
        $series = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $date   = $today->copy()->subDays($i)->format('Y-m-d');
            $borrow = DB::table('borrow_transactions')->whereDate('borrow_date', $date)->count();
            $return = DB::table('borrow_details')->whereDate('return_date', $date)->count();
            $series[] = ['date' => $date, 'borrow' => (int)$borrow, 'return' => (int)$return];
        }

        // Monthly grouping for long ranges
        $monthlyGroups = [];
        foreach ($series as $row) {
            $month = substr($row['date'], 0, 7); // YYYY-MM
            if (!isset($monthlyGroups[$month])) {
                $monthlyGroups[$month] = ['month' => $month, 'borrow' => 0, 'return' => 0];
            }
            $monthlyGroups[$month]['borrow'] += $row['borrow'];
            $monthlyGroups[$month]['return']  += $row['return'];
        }

        return response()->json([
            'code'    => 200,
            'results' => [
                'object' => [
                    'range'   => $range,
                    'days'    => $days,
                    'series'  => $series,
                    'monthly' => array_values($monthlyGroups),
                ],
            ],
        ]);
    }

    /**
     * GET /private/v1/dashboard/top-books
     * Top 10 most borrowed + top 10 most reserved.
     */
    public function getTopBooks()
    {
        $topBorrowed = DB::table('borrow_details as bd')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('authors as a', 'a.author_id', '=', 'b.author_id')
            ->select('b.book_id', 'b.title', 'b.cover_image', DB::raw('COALESCE(a.author_name,"") as author'), DB::raw('COUNT(*) as borrow_count'))
            ->groupBy('b.book_id', 'b.title', 'b.cover_image', 'a.author_name')
            ->orderByDesc('borrow_count')
            ->limit(10)
            ->get()
            ->values()
            ->map(fn ($r, $i) => [
                'rank'         => $i + 1,
                'book_id'      => $r->book_id,
                'title'        => $r->title,
                'author'       => $r->author,
                'cover_image'  => $r->cover_image,
                'borrow_count' => (int)$r->borrow_count,
            ]);

        $topReserved = DB::table('reservations as r')
            ->join('books as b', 'b.book_id', '=', 'r.book_id')
            ->leftJoin('authors as a', 'a.author_id', '=', 'b.author_id')
            ->select('b.book_id', 'b.title', 'b.cover_image', DB::raw('COALESCE(a.author_name,"") as author'), DB::raw('COUNT(*) as reservation_count'))
            ->groupBy('b.book_id', 'b.title', 'b.cover_image', 'a.author_name')
            ->orderByDesc('reservation_count')
            ->limit(10)
            ->get()
            ->values()
            ->map(fn ($r, $i) => [
                'rank'              => $i + 1,
                'book_id'           => $r->book_id,
                'title'             => $r->title,
                'author'            => $r->author,
                'cover_image'       => $r->cover_image,
                'reservation_count' => (int)$r->reservation_count,
            ]);

        return response()->json([
            'code'    => 200,
            'results' => [
                'object' => [
                    'top_borrowed' => $topBorrowed,
                    'top_reserved' => $topReserved,
                ],
            ],
        ]);
    }

    /**
     * GET /private/v1/dashboard/overdue
     * Full overdue list with per-copy severity classification.
     */
    public function getOverdueList()
    {
        $finePerDay = (int) DB::table('system_settings')
            ->where('config_key', 'fine_per_day')->value('config_value') ?: 5000;

        $rows = DB::table('borrow_transactions as bt')
            ->join('users as u', 'u.user_id', '=', 'bt.user_id')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'u.user_id')
            ->join('borrow_details as bd', function ($j) {
                $j->on('bd.borrow_id', '=', 'bt.borrow_id')->whereNull('bd.return_date');
            })
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('fines as f', function ($j) {
                $j->on('f.borrow_id', '=', 'bd.borrow_id')->on('f.copy_id', '=', 'bd.copy_id');
            })
            ->whereNull('bd.return_date')
            ->whereRaw('bt.due_date < CURDATE()')
            ->select([
                'bt.borrow_id',
                'bt.due_date',
                'u.user_id',
                'u.full_name',
                'u.email',
                'lc.card_number',
                'bc.barcode',
                'b.book_id',
                'b.title',
                DB::raw('DATEDIFF(CURDATE(), bt.due_date) as overdue_days'),
                DB::raw('COALESCE(f.amount, 0) as fine_amount'),
                DB::raw('COALESCE(f.status, "none") as fine_status'),
            ])
            ->orderByDesc('overdue_days')
            ->get()
            ->map(function ($r) use ($finePerDay) {
                $days = (int) $r->overdue_days;
                return [
                    'borrow_id'    => $r->borrow_id,
                    'due_date'     => $r->due_date,
                    'user_id'      => $r->user_id,
                    'full_name'    => $r->full_name,
                    'email'        => $r->email,
                    'card_number'  => $r->card_number,
                    'barcode'      => $r->barcode,
                    'book_id'      => $r->book_id,
                    'title'        => $r->title,
                    'overdue_days' => $days,
                    'severity'     => $days <= 3 ? 'light' : ($days <= 10 ? 'medium' : 'heavy'),
                    'fine_amount'  => (int) $r->fine_amount > 0
                        ? (int) $r->fine_amount
                        : $days * $finePerDay,
                    'fine_status'  => $r->fine_status,
                ];
            });

        // Summary counts
        $summary = [
            'total'  => $rows->count(),
            'light'  => $rows->where('severity', 'light')->count(),
            'medium' => $rows->where('severity', 'medium')->count(),
            'heavy'  => $rows->where('severity', 'heavy')->count(),
        ];

        return response()->json([
            'code'    => 200,
            'results' => [
                'object' => [
                    'summary' => $summary,
                    'rows'    => $rows->values(),
                ],
            ],
        ]);
    }
}
