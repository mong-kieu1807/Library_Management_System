<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SearchLogSeeder extends Seeder
{
    public function run(): void
    {
        $keywords = [
            // Không tìm thấy kết quả → result_count = 0 → sẽ vào import-suggestions
            ['keyword' => 'Đắc Nhân Tâm', 'result_count' => 0, 'count' => 14],
            ['keyword' => 'Nhà Giả Kim', 'result_count' => 0, 'count' => 11],
            ['keyword' => 'Tư Duy Phản Biện', 'result_count' => 0, 'count' => 9],
            ['keyword' => 'Lập Trình Python Căn Bản', 'result_count' => 0, 'count' => 8],
            ['keyword' => 'Trí Tuệ Nhân Tạo', 'result_count' => 0, 'count' => 7],
            ['keyword' => 'Người Giàu Có Nhất Thành Babylon', 'result_count' => 0, 'count' => 6],
            ['keyword' => 'Học Máy Với TensorFlow', 'result_count' => 0, 'count' => 5],
            ['keyword' => 'Khoa Học Dữ Liệu', 'result_count' => 0, 'count' => 5],
            ['keyword' => 'Kỹ Năng Thuyết Trình', 'result_count' => 0, 'count' => 4],
            ['keyword' => 'Triết Học Phương Đông', 'result_count' => 0, 'count' => 4],
            ['keyword' => 'Lịch Sử Việt Nam Cận Đại', 'result_count' => 0, 'count' => 3],
            ['keyword' => 'Kinh Tế Hành Vi', 'result_count' => 0, 'count' => 3],
            ['keyword' => 'Tâm Lý Học Đám Đông', 'result_count' => 0, 'count' => 2],
            // Có kết quả → sẽ không vào import-suggestions
            ['keyword' => 'Toán Giải Tích', 'result_count' => 3, 'count' => 5],
            ['keyword' => 'Vật Lý Đại Cương', 'result_count' => 2, 'count' => 4],
        ];

        $rows = [];
        $base = now()->subMonths(3);

        foreach ($keywords as $k) {
            for ($i = 0; $i < $k['count']; $i++) {
                $rows[] = [
                    'user_id'      => null,
                    'keyword'      => $k['keyword'],
                    'filters'      => null,
                    'result_count' => $k['result_count'],
                    'searched_at'  => $base->copy()->addDays(rand(0, 85))->format('Y-m-d H:i:s'),
                    'ip_address'   => '127.0.0.' . rand(1, 50),
                ];
            }
        }

        // Chunk to avoid TiDB packet limit
        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('search_logs')->insert($chunk);
        }

        $this->command->info('Inserted ' . count($rows) . ' search_logs rows.');
    }
}
