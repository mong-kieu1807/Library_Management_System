<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\BorrowController;

Route::post('/reservations', [ReservationController::class, 'reserveBook']);

Route::get(
    '/reservations/user/{userId}',
    [ReservationController::class, 'getUserReservations']
);

Route::delete(
    '/reservations/{reservationId}',
    [ReservationController::class, 'cancelReservation']
);

Route::get(
    '/borrowings/current/{userId}',
    [BorrowController::class, 'currentBorrowings']
);