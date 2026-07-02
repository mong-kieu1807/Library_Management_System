<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\LibraryCardController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BorrowingController;
use App\Http\Controllers\BookController as PublicBookController;
use App\Http\Controllers\Admin\BookController as AdminBookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\FineController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\Admin\BorrowTransactionController;
use App\Http\Controllers\Admin\ReturnController;
use App\Http\Controllers\Admin\RenewController;
use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Admin\ReceiptController;
use App\Http\Controllers\Admin\HistoryController;
use App\Http\Controllers\AIController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\ExportController;
use App\Http\Controllers\NotificationController;


Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-2fa', [AuthController::class, 'verify2fa'])->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword']);
    Route::get('/google',          [GoogleAuthController::class, 'redirect']);
    Route::get('/google/callback', [GoogleAuthController::class, 'callback']);
    Route::get('/verify-email',         [AuthController::class, 'verifyEmail']);
    Route::post('/resend-verification', [AuthController::class, 'resendVerification']);
});

Route::post('v1/ai/chat', [AIController::class, 'chat']);

Route::get('v1/public/shared/favorites/{token}', [WishlistController::class, 'publicView']);

Route::get('v1/books/filter-options', [PublicBookController::class, 'filterOptions']);
Route::get('v1/books/search', [PublicBookController::class, 'search']);
Route::get('v1/books/home',   [PublicBookController::class, 'home']);
Route::get('v1/books', [AdminBookController::class, 'index']);
Route::get('v1/books/{bookId}', [PublicBookController::class, 'show']);
Route::get('v1/books/{bookId}/related', [PublicBookController::class, 'related']);
Route::get('v1/books/{bookId}/reviews', [PublicBookController::class, 'reviews']);
Route::get('v1/books/{bookId}/review-permission', [PublicBookController::class, 'reviewPermission']);
Route::post('v1/books/{bookId}/reviews', [PublicBookController::class, 'submitReview']);
// Browser-navigation routes (window.open / download link) — Sanctum token cannot be sent via header
// from plain browser navigation, so these remain outside token-auth middleware.
Route::get('v1/book-copies/print-labels', [App\Http\Controllers\Admin\BookCopyController::class, 'printLabels']);
Route::get('v1/book-copies/export-excel', [App\Http\Controllers\Admin\BookCopyController::class, 'exportExcel']);
Route::get('v1/book-copies/export-pdf', [App\Http\Controllers\Admin\BookCopyController::class, 'exportPdfReport']);
// Receipt PDF — same pattern as book-copies exports: window.open() cannot send Authorization header
Route::get('private/v1/receipt/checkout/{borrow_id}', [ReceiptController::class, 'checkoutReceipt']);
Route::get('private/v1/receipt/return/{borrow_id}',   [ReceiptController::class, 'returnReceipt']);

// Report PDF exports — same window.open pattern (no auth header from browser navigation)
Route::get('private/v1/reports/export/overdue-pdf',        [ExportController::class, 'overdueBooksPdf']);
Route::get('private/v1/reports/export/transactions-pdf',   [ExportController::class, 'transactionsPdf']);
Route::get('private/v1/reports/export/fine-report-pdf',    [ExportController::class, 'fineReportPdf']);
// Report CSV/Excel exports — same no-auth pattern: window.open() / download link cannot send Bearer header
Route::get('private/v1/reports/export/transactions-csv',   [ExportController::class, 'transactionsCsv']);
Route::get('private/v1/reports/export/top-books-csv',      [ExportController::class, 'topBooksCsv']);
Route::get('private/v1/reports/export/top-authors-csv',    [ExportController::class, 'topAuthorsCsv']);
Route::get('private/v1/reports/export/top-categories-csv', [ExportController::class, 'topCategoriesCsv']);

Route::middleware(['auth:sanctum', 'role:admin,librarian'])->group(function () {
    Route::post('v1/books', [AdminBookController::class, 'store']);
    Route::get('v1/books/isbn/{isbn}', [AdminBookController::class, 'fetchByISBN']);
    Route::get('v1/books/{bookId}/admin-detail', [AdminBookController::class, 'show']);
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

    // Book copies management
    Route::get('v1/book-copies', [App\Http\Controllers\Admin\BookCopyController::class, 'index']);
    Route::post('v1/book-copies', [App\Http\Controllers\Admin\BookCopyController::class, 'store']);
    Route::put('v1/book-copies/{id}', [App\Http\Controllers\Admin\BookCopyController::class, 'update']);
    Route::delete('v1/book-copies/{id}', [App\Http\Controllers\Admin\BookCopyController::class, 'destroy']);
    Route::post('v1/book-copies/import', [App\Http\Controllers\Admin\BookCopyController::class, 'importCopies']);
    Route::get('v1/book-copies/summary-report', [App\Http\Controllers\Admin\BookCopyController::class, 'summaryReport']);
});

Route::get('v1/library-card/{userId}', [LibraryCardController::class, 'show']);

Route::prefix('v1/profile')->group(function () {
    // Change-password: auth handled in controller via PersonalAccessToken::findToken
    Route::post('/change-password/request', [ProfileController::class, 'requestChangePassword']);
    Route::post('/change-password/verify',  [ProfileController::class, 'verifyChangePassword']);

    // Public read — show profile (GET only, read-only)
    Route::get('/{userId}', [ProfileController::class, 'show']);

    // Write actions — must be authenticated
    Route::middleware('auth:sanctum')->group(function () {
        Route::put('/{userId}',         [ProfileController::class, 'update']);
        Route::post('/{userId}/avatar', [ProfileController::class, 'updateAvatar']);
    });
});
Route::middleware('auth:sanctum')->prefix('v1/me')->group(function () {
    // Notifications
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead']);

    // Library Card renewal (M1.6)
    Route::post('/library-card/renewal-request', [LibraryCardController::class, 'submitRenewalRequest']);
    Route::get('/library-card/renewal-requests', [LibraryCardController::class, 'myRenewalRequests']);

    Route::get('/borrowing', [BorrowingController::class, 'index']);
    Route::get('/borrowing/history', [BorrowingController::class, 'history']);
    Route::post('/borrowing/{borrowId}/renew', [BorrowingController::class, 'renew']);
    Route::get('/fines', [FineController::class, 'index']);
    Route::get('/wishlist',                 [WishlistController::class, 'index']);
    Route::post('/wishlist',                [WishlistController::class, 'store']);
    Route::patch('/wishlist/{wishlistId}',  [WishlistController::class, 'update']);
    Route::delete('/wishlist/{wishlistId}', [WishlistController::class, 'destroy']);

    Route::get('/reservations', [ReservationController::class, 'index']);
    Route::post('/reservations', [ReservationController::class, 'store']);
    Route::delete('/reservations/{reservationId}', [ReservationController::class, 'cancel']);
    Route::get('/recommendations',                   [RecommendationController::class, 'index']);
    Route::get('/recommendations/collaborative',     [RecommendationController::class, 'collaborative']);
    Route::post('/favorites/share',                  [WishlistController::class, 'share']);
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

    // Reader list + borrow history — accessible by both admin and librarians
    Route::get('/readers', [App\Http\Controllers\Admin\ReaderManagementController::class, 'index']);
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

// Module 6 — Báo cáo & Thống kê
Route::middleware(['auth:sanctum', 'role:admin,librarian'])->prefix('private/v1/reports')->group(function () {
    Route::get('/transactions',          [ReportController::class, 'transactions']);          // Phase 1
    Route::get('/top-books',             [ReportController::class, 'topBooks']);              // Phase 2
    Route::get('/top-readers',           [ReportController::class, 'topReaders']);            // Phase 3A
    Route::get('/reader-registrations',  [ReportController::class, 'readerRegistrations']);  // Phase 3B
    Route::get('/overdue-books',         [ReportController::class, 'overdueBooks']);          // Phase 4 (overdue)
    Route::get('/overdue-summary',       [ReportController::class, 'overdueSummary']);        // Phase 4 (overdue)
    Route::get('/top-authors',           [ReportController::class, 'topAuthors']);             // Phase 2 (authors)
    Route::get('/top-categories',        [ReportController::class, 'topCategories']);          // Phase 2 (categories)
    Route::get('/fine-revenue',          [ReportController::class, 'fineRevenue']);            // Phase 4 (fine)
    Route::get('/fine-reasons',          [ReportController::class, 'fineReasons']);            // Phase 4 (fine)
});

// Library Card Renewal — Admin (M1.6)
Route::middleware(['auth:sanctum', 'role:admin,librarian'])->prefix('private/v1/library-card-renewal')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\LibraryCardController::class, 'listRequests']);
    Route::post('/{id}/approve', [App\Http\Controllers\Admin\LibraryCardController::class, 'approve']);
    Route::post('/{id}/reject', [App\Http\Controllers\Admin\LibraryCardController::class, 'reject']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('private/v1')->group(function () {
    // Librarian Management Routes (Write actions remain admin only)
    Route::post('/librarians', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'store']);
    Route::patch('/librarians/{id}', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'update']);
    Route::delete('/librarians/{id}', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'destroy']);
    Route::post('/librarians/{id}/reset-password', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'resetPassword']);

    // Reader Management Routes (write actions — admin only)
    Route::patch('/readers/{id}/status', [App\Http\Controllers\Admin\ReaderManagementController::class, 'toggleStatus']);
    Route::post('/readers/{id}/reset-password', [App\Http\Controllers\Admin\ReaderManagementController::class, 'resetPassword']);

    // Dashboard Routes (admin only — legacy)
    Route::get('/dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'getDashboardData']);
    Route::get('/dashboard/recent-activities', [App\Http\Controllers\Admin\DashboardController::class, 'getRecentActivities']);
});
