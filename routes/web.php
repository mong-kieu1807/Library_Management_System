<?php
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'status' => 'OK',
        'message' => 'Library Management System BE is running.'
    ]);
});

Route::get('/db-test', function () {
    try {
        $pdo = DB::connection()->getPdo();
        $dbName = DB::connection()->getDatabaseName();

        // Ví dụ lấy thử dữ liệu từ bảng users (nếu có)
        $users = DB::table('users')->limit(5)->get();

        return view('welcome', [
            'dbStatus' => "Kết nối DB thành công: {$dbName}",
            'users' => $users,
        ]);
    } catch (\Exception $e) {
        return view('welcome', [
            'dbStatus' => 'Kết nối DB thất bại: ' . $e->getMessage(),
            'users' => collect(),
        ]);
    }
});