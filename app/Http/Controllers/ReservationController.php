<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Reservation;

class ReservationController extends Controller
{
    public function reserveBook(Request $request)
    {
        $request->validate([
            'user_id' => 'required',
            'book_id' => 'required'
        ]);

        $userId = $request->user_id;
        $bookId = $request->book_id;

        // Kiểm tra còn sách không
        $availableCopies = DB::table('book_copies')
            ->where('book_id', $bookId)
            ->where('status', 'available')
            ->count();

        if ($availableCopies > 0) {
            return response()->json([
                'message' => 'Sách vẫn còn bản sao khả dụng, không cần đặt trước.'
            ], 400);
        }

        // Kiểm tra đã đặt trước chưa
        $existing = Reservation::where('user_id', $userId)
            ->where('book_id', $bookId)
            ->whereIn('status', ['waiting', 'notified'])
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Bạn đã đặt trước sách này.'
            ], 400);
        }

        // Lấy vị trí cuối hàng chờ
        $lastPosition = Reservation::where('book_id', $bookId)
            ->max('queue_position');

        $queuePosition = ($lastPosition ?? 0) + 1;

        $reservation = Reservation::create([
            'user_id' => $userId,
            'book_id' => $bookId,
            'queue_position' => $queuePosition,
            'status' => 'waiting',
            'created_at' => now()
        ]);

        return response()->json([
            'message' => 'Đặt trước thành công',
            'queue_position' => $queuePosition,
            'reservation' => $reservation
        ]);
    }

        public function getUserReservations($userId)
        {
         $reservations = Reservation::join(
                'books',
                'reservations.book_id',
                '=',
                'books.book_id'
            )
            ->where('reservations.user_id', $userId)
            ->select(
                'reservations.reservation_id',
                'books.title',
                'reservations.queue_position',
                'reservations.status',
                'reservations.created_at'
            )
            ->orderBy('reservations.created_at', 'desc')
            ->get();

        return response()->json($reservations);
    }



    public function cancelReservation($reservationId)
    {
        $reservation = Reservation::find($reservationId);

        if (!$reservation) {
            return response()->json([
                'message' => 'Không tìm thấy đặt trước'
            ], 404);
        }

        $bookId = $reservation->book_id;
        $queuePosition = $reservation->queue_position;

        // Hủy đặt trước
        $reservation->status = 'cancelled';
        $reservation->save();

        // Cập nhật lại hàng chờ
        Reservation::where('book_id', $bookId)
            ->where('queue_position', '>', $queuePosition)
            ->whereIn('status', ['waiting', 'notified'])
            ->decrement('queue_position');

        return response()->json([
            'message' => 'Hủy đặt trước thành công'
        ]);
    }
}