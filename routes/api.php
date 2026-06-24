<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\LibraryCardController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BorrowingController;
use App\Http\Controllers\BookController as PublicBookController;
use App\Http\Controllers\Admin\BookController as AdminBookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\FineController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\Admin\BorrowTransactionController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\RenewController;
use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Admin\ReceiptController;
use App\Http\Controllers\Admin\HistoryController;


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
Route::get('v1/books/home',   [PublicBookController::class, 'home']);
Route::get('v1/books', [AdminBookController::class, 'index']);
Route::get('v1/books/{bookId}', [PublicBookController::class, 'show']);
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
Route::middleware('auth:sanctum')->prefix('v1/me')->group(function () {
    Route::get('/borrowing', [BorrowingController::class, 'index']);
    Route::get('/borrowing/history', [BorrowingController::class, 'history']);
    Route::post('/borrowing/{borrowId}/renew', [BorrowingController::class, 'renew']);
    Route::get('/reservations', [ReservationController::class, 'index']);
    Route::delete('/reservations/{reservationId}', [ReservationController::class, 'cancel']);
    Route::get('/fines', [FineController::class, 'index']);
    Route::get('/wishlist',                 [WishlistController::class, 'index']);
    Route::post('/wishlist',                [WishlistController::class, 'store']);
    Route::patch('/wishlist/{wishlistId}',  [WishlistController::class, 'update']);
    Route::delete('/wishlist/{wishlistId}', [WishlistController::class, 'destroy']);
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

    // Book Checkout (Check-out) + Renew
    Route::prefix('checkout')->group(function () {
        Route::get('/find-reader',          [BorrowTransactionController::class, 'findReader']);
        Route::get('/available-copies',     [BorrowTransactionController::class, 'searchAvailableCopies']);
        Route::get('/copy/{barcode}',       [BorrowTransactionController::class, 'validateCopy']);
        Route::post('/',              [BorrowTransactionController::class, 'store']);
        Route::get('/renew-list',     [RenewController::class, 'getRenewList']);
        Route::post('/renew',         [RenewController::class, 'renewBook']);
    });

    // Book Return (Check-in)
    Route::prefix('return')->group(function () {
        Route::get('/search-reader',            [ReturnController::class, 'searchReader']);
        Route::get('/borrowed-books/{user_id}', [ReturnController::class, 'getBorrowedBooks']);
        Route::get('/validate/{barcode}',       [ReturnController::class, 'validateReturnCopy']);
        Route::post('/confirm',                 [ReturnController::class, 'confirmReturn']);
    });

    // User history (read-only aggregation)
    Route::get('/users/{user_id}/history', [HistoryController::class, 'getUserHistory']);

    // Transaction log — Lịch sử giao dịch
    Route::get('/transactions/log', [HistoryController::class, 'getTransactionLog']);

    // Dashboard Analytics (admin + librarian access)
    Route::get('/dashboard/summary',   [App\Http\Controllers\Admin\DashboardController::class, 'getSummary']);
    Route::get('/dashboard/borrows',   [App\Http\Controllers\Admin\DashboardController::class, 'getBorrowStats']);
    Route::get('/dashboard/top-books', [App\Http\Controllers\Admin\DashboardController::class, 'getTopBooks']);
    Route::get('/dashboard/overdue',   [App\Http\Controllers\Admin\DashboardController::class, 'getOverdueList']);

    // PDF Receipts
    Route::prefix('receipt')->group(function () {
        Route::get('/checkout/{borrow_id}', [ReceiptController::class, 'checkoutReceipt']);
        Route::get('/return/{borrow_id}',   [ReceiptController::class, 'returnReceipt']);
    });

    // Reservation (Đặt trước sách)
    Route::prefix('reservation')->group(function () {
        Route::get('/search-book',  [AdminReservationController::class, 'searchBook']);
        Route::get('/list',         [AdminReservationController::class, 'listReservations']);
        Route::post('/create',      [AdminReservationController::class, 'createReservation']);
        Route::post('/confirm',     [AdminReservationController::class, 'confirmReservation']);
        Route::post('/cancel',      [AdminReservationController::class, 'cancelReservation']);
        Route::post('/expire',      [AdminReservationController::class, 'expireReservations']);
    });
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

    // Dashboard Routes (admin only — legacy)
    Route::get('/dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'getDashboardData']);
    Route::get('/dashboard/recent-activities', [App\Http\Controllers\Admin\DashboardController::class, 'getRecentActivities']);
});
