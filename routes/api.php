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
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\LibraryCardController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BorrowingController;
use App\Http\Controllers\BookController as PublicBookController;
use App\Http\Controllers\Admin\BookController as AdminBookController;
use App\Http\Controllers\ProfileController;


Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-2fa', [AuthController::class, 'verify2fa'])->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword']);
});

Route::get('v1/books/filter-options', [PublicBookController::class, 'filterOptions']);
Route::get('v1/books/search', [PublicBookController::class, 'search']);
Route::get('v1/books', [AdminBookController::class, 'index']);
Route::get('v1/books/{bookId}', [AdminBookController::class, 'show']);
Route::get('v1/books/{bookId}/related', [PublicBookController::class, 'related']);
Route::get('v1/books/{bookId}/reviews', [PublicBookController::class, 'reviews']);
Route::get('v1/books/{bookId}/review-permission', [PublicBookController::class, 'reviewPermission']);
Route::post('v1/books/{bookId}/reviews', [PublicBookController::class, 'submitReview']);

Route::middleware(['auth:sanctum', 'role:admin,librarian'])->group(function () {
    Route::post('v1/books', [AdminBookController::class, 'store']);
    Route::get('v1/books/isbn/{isbn}', [AdminBookController::class, 'fetchByISBN']);
    Route::put('v1/books/{bookId}', [AdminBookController::class, 'update']);
    Route::delete('v1/books/{bookId}', [AdminBookController::class, 'destroy']);

    // Category management
    Route::get('v1/categories', [App\Http\Controllers\Admin\CategoryController::class, 'index']);
    Route::post('v1/categories', [App\Http\Controllers\Admin\CategoryController::class, 'store']);
    Route::get('v1/categories/{id}', [App\Http\Controllers\Admin\CategoryController::class, 'show']);
    Route::put('v1/categories/{id}', [App\Http\Controllers\Admin\CategoryController::class, 'update']);
    Route::delete('v1/categories/{id}', [App\Http\Controllers\Admin\CategoryController::class, 'destroy']);

    // Author management
    Route::get('v1/authors', [App\Http\Controllers\Admin\AuthorController::class, 'index']);
    Route::post('v1/authors', [App\Http\Controllers\Admin\AuthorController::class, 'store']);
    Route::get('v1/authors/{id}', [App\Http\Controllers\Admin\AuthorController::class, 'show']);
    Route::put('v1/authors/{id}', [App\Http\Controllers\Admin\AuthorController::class, 'update']);
    Route::delete('v1/authors/{id}', [App\Http\Controllers\Admin\AuthorController::class, 'destroy']);
    Route::post('v1/authors/{id}/restore', [App\Http\Controllers\Admin\AuthorController::class, 'restore']);
});

Route::get('v1/library-card/{userId}', [LibraryCardController::class, 'show']);

Route::prefix('v1/profile')->group(function () {
    // Change-password: specific literals BEFORE {userId} wildcard
    // Auth is handled in controller via PersonalAccessToken::findToken (same pattern as logout)
    Route::post('/change-password/request', [ProfileController::class, 'requestChangePassword']);
    Route::post('/change-password/verify',  [ProfileController::class, 'verifyChangePassword']);

    // Wildcard routes — no auth middleware (userId in URL, legacy)
    Route::get('/{userId}',         [ProfileController::class, 'show']);
    Route::put('/{userId}',         [ProfileController::class, 'update']);
    Route::post('/{userId}/avatar', [ProfileController::class, 'updateAvatar']);
});
Route::middleware(['auth:sanctum', 'role:admin,librarian'])->prefix('private/v1')->group(function () {
    Route::get('/users', [App\Http\Controllers\Admin\UserController::class, 'index']);
    Route::post('/users', [App\Http\Controllers\Admin\UserController::class, 'store']);
    Route::get('/users/{id}', [App\Http\Controllers\Admin\UserController::class, 'show']);
    Route::patch('/users/{id}', [App\Http\Controllers\Admin\UserController::class, 'update']);
    Route::delete('/users/{id}', [App\Http\Controllers\Admin\UserController::class, 'destroy']);
    Route::post('/users/{id}/reset-password', [App\Http\Controllers\Admin\UserController::class, 'resetPassword']);
    
    // Librarian Management (List only for both admin and librarians)
    Route::get('/librarians', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'index']);

    // Reader borrow history for both admin and librarians
    Route::get('/readers/{id}/borrow-history', [App\Http\Controllers\Admin\ReaderManagementController::class, 'borrowHistory']);

    // Access Audit Logs (Login Logs) for both admin and librarians
    Route::get('/login-logs', [App\Http\Controllers\Admin\LoginLogController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('private/v1')->group(function () {
    // Librarian Management Routes (Write actions remain admin only)
    Route::post('/librarians', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'store']);
    Route::patch('/librarians/{id}', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'update']);
    Route::delete('/librarians/{id}', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'destroy']);
    Route::post('/librarians/{id}/reset-password', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'resetPassword']);

    // Reader Management Routes
    Route::get('/readers', [App\Http\Controllers\Admin\ReaderManagementController::class, 'index']);
    Route::patch('/readers/{id}/status', [App\Http\Controllers\Admin\ReaderManagementController::class, 'toggleStatus']);
    Route::post('/readers/{id}/reset-password', [App\Http\Controllers\Admin\ReaderManagementController::class, 'resetPassword']);

    // Dashboard Routes
    Route::get('/dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'getDashboardData']);
    Route::get('/dashboard/recent-activities', [App\Http\Controllers\Admin\DashboardController::class, 'getRecentActivities']);
});
