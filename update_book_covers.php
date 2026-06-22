<?php
// Bootstrap Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->handle($request = \Illuminate\Http\Request::capture());

use App\Models\Book;
use Illuminate\Support\Facades\Http;

// Lấy tất cả sách trong cơ sở dữ liệu
$books = Book::all();
$successCount = 0;
$failCount = 0;
$skipCount = 0;

echo "============================================================\n";
echo " BẮT ĐẦU CẬP NHẬT ẢNH BÌA SÁCH TỪ GOOGLE BOOKS\n";
echo "============================================================\n";

foreach ($books as $book) {
    // Nếu sách đã có ảnh online dạng http/https thì bỏ qua
    if ($book->cover_image && (strpos($book->cover_image, 'http://') === 0 || strpos($book->cover_image, 'https://') === 0)) {
        $skipCount++;
        continue;
    }

    $isbn = trim($book->isbn ?? '');
    $title = trim($book->title ?? '');
    $query = '';

    if ($isbn) {
        $query = "isbn:" . $isbn;
    } elseif ($title) {
        $query = "intitle:" . urlencode($title);
    } else {
        echo "Bỏ qua: \"{$book->title}\" (Thiếu cả ISBN và Tiêu đề)\n";
        $skipCount++;
        continue;
    }

    echo "Đang tìm ảnh cho: \"{$book->title}\" ({$query})... ";

    try {
        $response = Http::get("https://www.googleapis.com/books/v1/volumes?q=" . $query);
        if ($response->successful()) {
            $data = $response->json();
            $thumbnail = null;

            if (isset($data['items'][0]['volumeInfo']['imageLinks']['thumbnail'])) {
                $thumbnail = $data['items'][0]['volumeInfo']['imageLinks']['thumbnail'];
            } elseif (isset($data['items'][0]['volumeInfo']['imageLinks']['smallThumbnail'])) {
                $thumbnail = $data['items'][0]['volumeInfo']['imageLinks']['smallThumbnail'];
            }

            if ($thumbnail) {
                // Đổi đường dẫn http sang https để tránh lỗi bảo mật hỗn hợp (mixed content)
                $thumbnail = str_replace('http://', 'https://', $thumbnail);

                $book->cover_image = $thumbnail;
                $book->save();
                echo "THÀNH CÔNG (Đã cập nhật)\n";
                $successCount++;
            } else {
                echo "KHÔNG TÌM THẤY ẢNH\n";
                $failCount++;
            }
        } else {
            echo "LỖI API (Mã phản hồi: " . $response->status() . ")\n";
            $failCount++;
        }
    } catch (\Exception $e) {
        echo "LỖI KẾT NỐI: " . $e->getMessage() . "\n";
        $failCount++;
    }

    // Nghỉ 200ms giữa các request để tránh rate limit của Google API
    usleep(200000);
}

echo "============================================================\n";
echo " HOÀN THÀNH!\n";
echo " - Cập nhật mới thành công: {$successCount}\n";
echo " - Bỏ qua (đã có ảnh/thiếu thông tin): {$skipCount}\n";
echo " - Thất bại/Không tìm thấy ảnh: {$failCount}\n";
echo "============================================================\n";
