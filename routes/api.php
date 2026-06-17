<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\BookController;

Route::get('/books/isbn/{isbn}', [BookController::class, 'fetchByISBN']);
Route::apiResource('books', BookController::class);
