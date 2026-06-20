<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;


Route::prefix('v1/auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout']);
});

Route::middleware('auth:sanctum')->prefix('private/v1')->group(function () {
    Route::get('/users', [App\Http\Controllers\Admin\UserManagementController::class, 'index']);
    Route::post('/users', [App\Http\Controllers\Admin\UserManagementController::class, 'store']);
    Route::get('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'show']);
    Route::patch('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'update']);
    Route::delete('/users/{id}', [App\Http\Controllers\Admin\UserManagementController::class, 'destroy']);
    Route::post('/users/{id}/reset-password', [App\Http\Controllers\Admin\UserManagementController::class, 'resetPassword']);
});
