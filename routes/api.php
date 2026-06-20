<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\LibraryCardController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\ProfileController;


Route::prefix('v1/auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword']);
});

Route::get('v1/books/filter-options', [BookController::class, 'filterOptions']);
Route::get('v1/books/search', [BookController::class, 'search']);
Route::get('v1/books/home', [BookController::class, 'home']);
Route::get('v1/books/{bookId}', [BookController::class, 'show']);
Route::get('v1/books/{bookId}/related', [BookController::class, 'related']);
Route::get('v1/books/{bookId}/reviews', [BookController::class, 'reviews']);
Route::get('v1/books/{bookId}/review-permission', [BookController::class, 'reviewPermission']);
Route::post('v1/books/{bookId}/reviews', [BookController::class, 'submitReview']);

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

Route::middleware('auth:sanctum')->prefix('private/v1')->group(function () {
    Route::get('/users', [App\Http\Controllers\Admin\UserManagementController::class, 'index']);
    Route::post('/users', [App\Http\Controllers\Admin\UserManagementController::class, 'store']);
    Route::get('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'show']);
    Route::patch('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'update']);
    Route::delete('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'destroy']);
    Route::post('/users/{id}/reset-password', [App\Http\Controllers\Admin\UserManagementController::class, 'resetPassword']);
});
