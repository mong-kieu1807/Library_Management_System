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
        $thumbnail = null;
        $source = '';

        // 1. Thử lấy ảnh bìa từ Open Library trước (Không cần API Key, không giới hạn rate limit)
        $olData = null;
        if ($isbn) {
            $olResponse = Http::get("https://openlibrary.org/search.json?q=isbn:" . $isbn . "&limit=1");
            if ($olResponse->successful()) {
                $olData = $olResponse->json();
            }
        }
        
        // Nếu tìm theo ISBN không có kết quả, thử tìm theo tiêu đề sách làm phương án dự phòng
        if (empty($olData['docs'])) {
            $olResponse = Http::get("https://openlibrary.org/search.json?title=" . urlencode($title) . "&limit=1");
            if ($olResponse->successful()) {
                $olData = $olResponse->json();
            }
        }

        if (!empty($olData['docs'][0]['cover_i'])) {
            $thumbnail = "https://covers.openlibrary.org/b/id/" . $olData['docs'][0]['cover_i'] . "-L.jpg";
            $source = "Open Library";
        } elseif (!empty($olData['docs'][0]['cover_edition_key'])) {
            $thumbnail = "https://covers.openlibrary.org/b/olid/" . $olData['docs'][0]['cover_edition_key'] . "-L.jpg";
            $source = "Open Library";
        }

        // 2. Nếu Open Library không tìm thấy hoặc thất bại, thử Google Books API làm phương án dự phòng
        if (!$thumbnail) {
            $response = Http::get("https://www.googleapis.com/books/v1/volumes?q=" . $query);
            if ($response->successful()) {
                $data = $response->json();
                if (isset($data['items'][0]['volumeInfo']['imageLinks']['thumbnail'])) {
                    $thumbnail = $data['items'][0]['volumeInfo']['imageLinks']['thumbnail'];
                    $source = "Google Books";
                } elseif (isset($data['items'][0]['volumeInfo']['imageLinks']['smallThumbnail'])) {
                    $thumbnail = $data['items'][0]['volumeInfo']['imageLinks']['smallThumbnail'];
                    $source = "Google Books";
                }
            }
        }

        if ($thumbnail) {
            // Đổi đường dẫn http sang https để tránh lỗi bảo mật hỗn hợp (mixed content)
            $thumbnail = str_replace('http://', 'https://', $thumbnail);

            $book->cover_image = $thumbnail;
            $book->save();
            echo "THÀNH CÔNG (Đã cập nhật từ {$source})\n";
            $successCount++;
        } else {
            echo "KHÔNG TÌM THẤY ẢNH\n";
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
