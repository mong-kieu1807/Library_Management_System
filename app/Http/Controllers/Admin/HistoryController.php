<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    /**
     * GET /private/v1/users/{user_id}/history
     *
     * Tổng hợp lịch sử toàn diện của một độc giả.
     * 4 queries, không N+1.
     */
    public function getUserHistory(int $userId)
    {
        $user = DB::table('users as u')
            ->leftJoin('library_cards as lc', 'lc.user_id', '=', 'u.user_id')
            ->where('u.user_id', $userId)
            ->select(['u.user_id', 'u.full_name', 'u.email', 'u.phone', 'lc.card_number'])
            ->first();

        if (!$user) {
            return response()->json(['message' => 'Không tìm thấy người dùng.'], 404);
        }

        $today = Carbon::today();
        $due   = null; // will be set per row

        // ── [1] CURRENT BORROWS ─────────────────────────────────────────────
        $currentBorrows = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', function ($j) {
                $j->on('bd.borrow_id', '=', 'bt.borrow_id')
                  ->whereNull('bd.return_date');
            })
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('bt.user_id', $userId)
            ->whereIn('bt.status', ['active', 'overdue'])
            ->select([
                'bt.borrow_id', 'bt.borrow_date', 'bt.due_date', 'bt.status',
                'bd.copy_id', 'bd.renew_count',
                'bc.barcode',
                'b.book_id', 'b.title', 'b.cover_image',
            ])
            ->orderByDesc('bt.borrow_id')
            ->get()
            ->map(function ($row) use ($today) {
                $dueDate     = Carbon::parse($row->due_date)->startOfDay();
                $overdueDays = $today->gt($dueDate) ? (int) $today->diffInDays($dueDate, true) : 0;
                return [
                    'borrow_id'    => $row->borrow_id,
                    'borrow_date'  => $row->borrow_date,
                    'due_date'     => $row->due_date,
                    'status'       => $row->status,
                    'copy_id'      => $row->copy_id,
                    'barcode'      => $row->barcode,
                    'book_id'      => $row->book_id,
                    'title'        => $row->title,
                    'cover_image'  => $row->cover_image,
                    'renew_count'  => (int) $row->renew_count,
                    'overdue_days' => $overdueDays,
                    'is_overdue'   => $overdueDays > 0,
                ];
            });

        // ── [2] BORROW HISTORY (returned only) ──────────────────────────────
        $borrowHistory = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', function ($j) {
                $j->on('bd.borrow_id', '=', 'bt.borrow_id')
                  ->whereNotNull('bd.return_date');
            })
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->leftJoin('fines as f', function ($j) {
                $j->on('f.borrow_id', '=', 'bd.borrow_id')
                  ->on('f.copy_id', '=', 'bd.copy_id');
            })
            ->where('bt.user_id', $userId)
            ->select([
                'bt.borrow_id', 'bt.borrow_date', 'bt.due_date',
                'bd.copy_id', 'bd.return_date', 'bd.condition_return', 'bd.renew_count',
                'bc.barcode',
                'b.book_id', 'b.title',
                DB::raw('f.amount AS fine_amount'),
                DB::raw('f.status AS fine_status'),
            ])
            ->orderByDesc('bd.return_date')
            ->get()
            ->map(function ($row) {
                $dueDate     = Carbon::parse($row->due_date)->startOfDay();
                $retDate     = Carbon::parse($row->return_date)->startOfDay();
                $overdueDays = $retDate->gt($dueDate) ? (int) $retDate->diffInDays($dueDate, true) : 0;
                return [
                    'borrow_id'       => $row->borrow_id,
                    'borrow_date'     => $row->borrow_date,
                    'due_date'        => $row->due_date,
                    'return_date'     => $row->return_date,
                    'copy_id'         => $row->copy_id,
                    'barcode'         => $row->barcode,
                    'book_id'         => $row->book_id,
                    'title'           => $row->title,
                    'condition_return'=> $row->condition_return,
                    'renew_count'     => (int) $row->renew_count,
                    'overdue_days'    => $overdueDays,
                    'fine_amount'     => (int) ($row->fine_amount ?? 0),
                    'fine_status'     => $row->fine_status,
                ];
            });

        // ── [3] FINE HISTORY ────────────────────────────────────────────────
        $fines = DB::table('fines as f')
            ->leftJoin('book_copies as bc', 'bc.copy_id', '=', 'f.copy_id')
            ->leftJoin('books as b', 'b.book_id', '=', 'bc.book_id')
            ->where('f.user_id', $userId)
            ->select([
                'f.fine_id', 'f.borrow_id', 'f.copy_id',
                'f.amount', 'f.reason', 'f.status', 'f.created_at',
                'bc.barcode',
                'b.title',
            ])
            ->orderByDesc('f.created_at')
            ->get()
            ->map(fn ($row) => [
                'fine_id'   => $row->fine_id,
                'borrow_id' => $row->borrow_id,
                'copy_id'   => $row->copy_id,
                'barcode'   => $row->barcode ?? null,
                'title'     => $row->title ?? null,
                'amount'    => (int) $row->amount,
                'reason'    => $row->reason,
                'status'    => $row->status,
                'created_at'=> $row->created_at,
            ]);

        // ── [4] RESERVATION HISTORY ─────────────────────────────────────────
        $reservations = DB::table('reservations as r')
            ->join('books as b', 'b.book_id', '=', 'r.book_id')
            ->where('r.user_id', $userId)
            ->select([
                'r.reservation_id', 'r.book_id', 'r.queue_position',
                'r.status', 'r.notified_at', 'r.expired_at', 'r.created_at',
                'b.title', 'b.cover_image',
            ])
            ->orderByDesc('r.created_at')
            ->get()
            ->map(fn ($row) => [
                'reservation_id' => $row->reservation_id,
                'book_id'        => $row->book_id,
                'title'          => $row->title,
                'cover_image'    => $row->cover_image,
                'queue_position' => $row->queue_position,
                'status'         => $row->status,
                'notified_at'    => $row->notified_at,
                'expired_at'     => $row->expired_at,
                'created_at'     => $row->created_at,
            ]);

        return response()->json([
            'code'    => 200,
            'results' => [
                'user'           => [
                    'user_id'     => $user->user_id,
                    'full_name'   => $user->full_name,
                    'email'       => $user->email,
                    'phone'       => $user->phone,
                    'card_number' => $user->card_number,
                ],
                'current_borrows' => $currentBorrows,
                'history'         => $borrowHistory,
                'fines'           => $fines,
                'reservations'    => $reservations,
            ],
        ]);
    }

    /**
     * GET /private/v1/transactions/log
     *
     * Lịch sử giao dịch toàn hệ thống (mượn / trả / gia hạn).
     * ?q=       — tìm theo mã GD, tên độc giả, tên sách
     * ?type=    — borrow | return | renew
     * ?date=    — lọc ngày (YYYY-MM-DD)
     * ?page=    — trang (default 1)
     * ?per_page= — dòng/trang (default 20)
     */
    public function getTransactionLog(\Illuminate\Http\Request $request)
    {
        $q       = trim($request->input('q', ''));
        $type    = $request->input('type', '');
        $date    = $request->input('date', '');
        $page    = max(1, (int) $request->input('page', 1));
        $perPage = min(100, max(5, (int) $request->input('per_page', 20)));

        $today = Carbon::today();

        $query = DB::table('borrow_details as bd')
            ->join('borrow_transactions as bt', 'bt.borrow_id', '=', 'bd.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->join('users as reader', 'reader.user_id', '=', 'bt.user_id')
            ->leftJoin('users as lib', 'lib.user_id', '=', 'bt.librarian_id')
            ->leftJoin('fines as f', function ($j) {
                $j->on('f.borrow_id', '=', 'bd.borrow_id')
                  ->on('f.copy_id', '=', 'bd.copy_id');
            })
            ->select([
                'bt.borrow_id', 'bd.copy_id',
                'bd.return_date', 'bd.renew_count',
                'bt.borrow_date', 'bt.due_date',
                'b.title',
                'reader.full_name as reader_name',
                'lib.full_name as librarian_name',
                DB::raw('COALESCE(f.amount, 0) as fine_amount'),
                DB::raw('f.status as fine_status'),
            ]);

        if ($type === 'borrow') {
            $query->whereNull('bd.return_date')->where('bd.renew_count', 0);
        } elseif ($type === 'return') {
            $query->whereNotNull('bd.return_date');
        } elseif ($type === 'renew') {
            $query->whereNull('bd.return_date')->where('bd.renew_count', '>', 0);
        }

        if ($q !== '') {
            $query->where(function ($w) use ($q) {
                $w->where('b.title', 'LIKE', "%{$q}%")
                  ->orWhere('reader.full_name', 'LIKE', "%{$q}%")
                  ->orWhere(DB::raw("CONCAT('GD-', LPAD(bt.borrow_id,4,'0'))"), 'LIKE', "%{$q}%");
            });
        }

        if ($date !== '') {
            $query->where(function ($w) use ($date) {
                $w->where('bt.borrow_date', $date)
                  ->orWhere('bd.return_date', $date);
            });
        }

        $total = $query->count();

        $rows = (clone $query)
            ->orderByDesc('bt.borrow_id')
            ->orderByDesc(DB::raw('COALESCE(bd.return_date, bt.borrow_date)'))
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $items = $rows->map(function ($row) use ($today) {
            $due = Carbon::parse($row->due_date)->startOfDay();

            if (!is_null($row->return_date)) {
                $eventType = 'return';
                $eventDate = $row->return_date;
            } elseif ((int) $row->renew_count > 0) {
                $eventType = 'renew';
                $eventDate = $row->borrow_date;
            } else {
                $eventType = 'borrow';
                $eventDate = $row->borrow_date;
            }

            if (!is_null($row->return_date)) {
                $returnDay = Carbon::parse($row->return_date)->startOfDay();
                $txStatus  = $returnDay->gt($due) ? 'overdue_returned' : 'returned_on_time';
            } elseif ($today->gt($due)) {
                $txStatus = 'overdue';
            } else {
                $txStatus = 'active';
            }

            return [
                'borrow_id'      => $row->borrow_id,
                'copy_id'        => $row->copy_id,
                'ma_gd'          => 'GD-' . str_pad($row->borrow_id, 4, '0', STR_PAD_LEFT),
                'event_type'     => $eventType,
                'event_date'     => $eventDate,
                'reader_name'    => $row->reader_name,
                'book_title'     => $row->title,
                'librarian_name' => $row->librarian_name ?? 'Hệ thống',
                'tx_status'      => $txStatus,
                'fine_amount'    => (float) $row->fine_amount,
                'fine_status'    => $row->fine_status,
            ];
        });

        return response()->json([
            'code'    => 200,
            'results' => [
                'objects' => $items,
                'meta'    => [
                    'total'     => $total,
                    'page'      => $page,
                    'per_page'  => $perPage,
                    'last_page' => (int) ceil($total / $perPage),
                ],
            ],
        ]);
    }
}
