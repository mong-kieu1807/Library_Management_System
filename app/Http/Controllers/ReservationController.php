<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class ReservationController extends Controller
{
    public function cancel(Request $request, int $reservationId)
    {
        $userId = auth()->id();

        $reservation = DB::table('reservations')
            ->where('reservation_id', $reservationId)
            ->where('user_id', $userId)
            ->first();

        if (!$reservation) {
            return response()->json(['message' => 'Không tìm thấy đặt trước.'], 404);
        }

        if ($reservation->status !== 'waiting') {
            return response()->json(['message' => 'Chỉ có thể hủy đặt trước đang ở trạng thái chờ.'], 422);
        }

        DB::transaction(function () use ($reservation) {
            DB::table('reservations')
                ->where('reservation_id', $reservation->reservation_id)
                ->update(['status' => 'cancelled']);

            DB::table('reservations')
                ->where('book_id', $reservation->book_id)
                ->where('status', 'waiting')
                ->where('queue_position', '>', $reservation->queue_position)
                ->decrement('queue_position');
        });

        return response()->json(['message' => 'Hủy đặt trước thành công.']);
    }

    public function index(Request $request)
    {
        $userId = auth()->id();

        $rows = DB::table('reservations as r')
            ->join('books as b', 'b.book_id', '=', 'r.book_id')
            ->where('r.user_id', $userId)
            ->select([
                'r.reservation_id',
                'r.book_id',
                'b.title',
                'b.cover_image',
                'b.avg_rating',
                DB::raw("(SELECT GROUP_CONCAT(a.author_name ORDER BY a.author_name SEPARATOR ', ')
                          FROM authors a
                          JOIN book_authors ba ON ba.author_id = a.author_id
                          WHERE ba.book_id = r.book_id) as author_name"),
                DB::raw("(SELECT c.category_name
                          FROM categories c
                          JOIN book_categories bc ON bc.category_id = c.category_id
                          WHERE bc.book_id = r.book_id
                          LIMIT 1) as category_name"),
                DB::raw("(SELECT COUNT(*)
                          FROM reservations
                          WHERE book_id = r.book_id
                          AND status IN ('waiting', 'ready')) as total_queue"),
                DB::raw("DATE_FORMAT(r.created_at, '%Y-%m-%d') as reserved_at"),
                'r.status',
                'r.queue_position',
                DB::raw("DATE_FORMAT(r.notified_at, '%Y-%m-%d') as notified_at"),
                DB::raw("DATE_FORMAT(r.expired_at, '%Y-%m-%d') as expired_at"),
            ])
            ->orderByDesc('r.created_at')
            ->get();

        $data = $rows->map(function ($row) {
            $row->avg_rating    = (float) $row->avg_rating;
            $row->total_queue   = (int)   $row->total_queue;
            $row->queue_position = (int)  $row->queue_position;
            return $row;
        });

        return response()->json(['data' => $data]);
    }
}
