<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;

Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/verify-2fa', [AuthController::class, 'verify2fa'])->middleware('auth:sanctum');
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->prefix('private/v1')->group(function () {
    Route::get('/users', [App\Http\Controllers\Admin\UserManagementController::class, 'index']);
    Route::post('/users', [App\Http\Controllers\Admin\UserManagementController::class, 'store']);
    Route::get('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'show']);
    Route::patch('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'update']);
    Route::delete('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'destroy']);
    Route::post('/users/{id}/reset-password', [App\Http\Controllers\Admin\UserManagementController::class, 'resetPassword']);

    // Librarian Management Routes
    Route::get('/librarians', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'index']);
    Route::post('/librarians', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'store']);
    Route::patch('/librarians/{id}', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'update']);
    Route::delete('/librarians/{id}', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'destroy']);
    Route::post('/librarians/{id}/reset-password', [App\Http\Controllers\Admin\LibrarianManagementController::class, 'resetPassword']);

    // Reader Management Routes
    Route::get('/readers', [App\Http\Controllers\Admin\ReaderManagementController::class, 'index']);
    Route::patch('/readers/{id}/status', [App\Http\Controllers\Admin\ReaderManagementController::class, 'toggleStatus']);
    Route::post('/readers/{id}/reset-password', [App\Http\Controllers\Admin\ReaderManagementController::class, 'resetPassword']);
    Route::get('/readers/{id}/borrow-history', [App\Http\Controllers\Admin\ReaderManagementController::class, 'borrowHistory']);

    // Access Audit Logs (Login Logs)
    Route::get('/login-logs', [App\Http\Controllers\Admin\LoginLogController::class, 'index']);

    // Dashboard Routes
    Route::get('/dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'getDashboardData']);
    Route::get('/dashboard/recent-activities', [App\Http\Controllers\Admin\DashboardController::class, 'getRecentActivities']);
});
