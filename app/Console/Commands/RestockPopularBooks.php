<?php

namespace App\Console\Commands;

use App\Models\BookCopy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cộng thêm bản sao cho các sách có nhiều bình luận. Đếm bình luận từ bảng `reviews`
 * thật (KHÔNG dùng cột books.total_reviews — cột đó là số đếm cache đã lỗi thời,
 * không khớp dữ liệu thật, xem báo cáo trước đó).
 */
class RestockPopularBooks extends Command
{
    protected $signature = 'books:restock-popular
        {--min=100 : Số bình luận tối thiểu để tính là sách nổi bật}
        {--max=500 : Số bình luận tối đa (0 = không giới hạn trên)}
        {--copies=5 : Số bản sao cộng thêm cho mỗi sách đạt tiêu chí}
        {--dry-run : Chỉ liệt kê sách sẽ được cộng bản sao, không ghi DB}';

    protected $description = 'Cộng thêm bản sao cho sách có số bình luận thật trong khoảng --min..--max';

    public function handle(): void
    {
        $min = (int) $this->option('min');
        $max = (int) $this->option('max');
        $copiesPerBook = (int) $this->option('copies');
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('books as b')
            ->join('reviews as r', 'b.book_id', '=', 'r.book_id')
            ->select('b.book_id', 'b.title', DB::raw('COUNT(r.review_id) as review_count'))
            ->groupBy('b.book_id', 'b.title')
            ->havingRaw('COUNT(r.review_id) >= ?', [$min]);

        if ($max > 0) {
            $query->havingRaw('COUNT(r.review_id) <= ?', [$max]);
        }

        $books = $query->orderByDesc('review_count')->get();

        if ($books->isEmpty()) {
            $this->warn("Không có sách nào có từ {$min} bình luận trở lên" . ($max > 0 ? " (tối đa {$max})" : '') . " trong bảng reviews thật.");
            return;
        }

        foreach ($books as $book) {
            $this->line("\"{$book->title}\" (book_id={$book->book_id}) — {$book->review_count} bình luận" . ($dryRun ? '' : " → +{$copiesPerBook} bản sao"));

            if ($dryRun) {
                continue;
            }

            for ($i = 0; $i < $copiesPerBook; $i++) {
                BookCopy::create([
                    'book_id'          => $book->book_id,
                    'barcode'          => BookCopy::generateUniqueBarcode(),
                    'status'           => 'available',
                    'condition'        => 'good',
                    'shelf_location'   => null,
                    'acquisition_date' => now()->toDateString(),
                ]);
            }
        }

        $this->info(($dryRun ? '[dry-run] Sẽ cộng bản sao cho ' : 'Đã cộng bản sao cho ') . $books->count() . ' sách.');
    }
}
