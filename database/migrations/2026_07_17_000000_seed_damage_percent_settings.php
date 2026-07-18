<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Config key cho % bồi thường theo từng mức độ hư hỏng khi trả sách —
     * FineService::getDamagePercent() đọc các key này, hiện chưa tồn tại trong
     * system_settings nên luôn rơi về giá trị mặc định hard-code trong PHP.
     * Seed đủ cả 4 mức (kể cả 3 mức cũ minor/heavy/lost) để nhất quán, và thêm
     * mức "medium" (Hư vừa) mới cho luồng xác nhận tình trạng sách khi trả.
     */
    private array $keys = [
        'damage_minor_percent'  => '20',
        'damage_medium_percent' => '35',
        'damage_heavy_percent'  => '50',
        'damage_lost_percent'   => '100',
    ];

    public function up(): void
    {
        // TiDB: setting_id không có AUTO_INCREMENT -> tự sinh id bằng max()+1 trong
        // transaction (cùng pattern 2026_07_02_020000_seed_module7_system_settings.php).
        DB::transaction(function () {
            foreach ($this->keys as $key => $value) {
                $exists = DB::table('system_settings')
                    ->where('config_key', $key)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $nextId = (int) (DB::table('system_settings')->lockForUpdate()->max('setting_id') ?? 0) + 1;

                DB::table('system_settings')->insert([
                    'setting_id'   => $nextId,
                    'config_key'   => $key,
                    'config_value' => $value,
                    'updated_at'   => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        DB::table('system_settings')->whereIn('config_key', array_keys($this->keys))->delete();
    }
};
