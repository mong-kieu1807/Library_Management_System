<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WeeklySummaryService
{
    public function getActiveReaders()
    {
        return User::whereHas('role', function ($query) {
            $query->where('role_name', 'reader');
        })->where('status', 1)
          ->whereNotNull('email')
          ->get();
    }

    public function getSummaryForUser(User $user): array
    {
        $today = Carbon::today();

        $borrowRows = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bt.user_id', $user->user_id)
            ->whereNull('bd.return_date')
            ->select(['bt.due_date', 'b.title'])
            ->orderBy('bt.due_date', 'asc')
            ->get();

        $borrowedCount = $borrowRows->count();
        $closestDueDate = null;
        $dueSoon = [];

        if ($borrowedCount > 0) {
            $closestDueDate = Carbon::parse($borrowRows->first()->due_date)->format('d/m/Y');

            $dueSoon = $borrowRows
                ->filter(function ($row) use ($today) {
                    $dueDate = Carbon::parse($row->due_date)->startOfDay();
                    $daysUntilDue = $today->diffInDays($dueDate, false);
                    return $daysUntilDue >= 0 && $daysUntilDue <= 3;
                })
                ->unique('title')
                ->map(function ($row) {
                    return [
                        'title' => $row->title,
                        'due_date' => Carbon::parse($row->due_date)->format('d/m/Y'),
                    ];
                })
                ->values()
                ->all();
        }

        $unpaidFine = (int) DB::table('fines')
            ->where('user_id', $user->user_id)
            ->where('status', 'unpaid')
            ->sum('amount');

        return [
            'user' => $user,
            'borrowed_count' => $borrowedCount,
            'closest_due_date' => $closestDueDate,
            'unpaid_fine' => $unpaidFine,
            'due_soon' => $dueSoon,
        ];
    }
}
