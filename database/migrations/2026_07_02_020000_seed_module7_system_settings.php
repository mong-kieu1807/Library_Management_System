<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * New config_key rows required by Module 7 that don't exist yet in
     * system_settings. Values match the currently hard-coded behavior
     * elsewhere in the app, so applying this migration does not change
     * any runtime behavior until a later phase reads these keys.
     *
     * - card_regular_borrow_limit / card_regular_max_days   -> Thẻ Thường
     * - card_priority_borrow_limit / card_priority_max_days -> Thẻ Ưu tiên
     * - renew_extend_days -> matches BorrowingController::renew() "+7 days" hard-code
     */
    private array $keys = [
        'card_regular_borrow_limit'  => '3',
        'card_regular_max_days'      => '14',
        'card_priority_borrow_limit' => '5',
        'card_priority_max_days'     => '21',
        'renew_extend_days'          => '7',
    ];

    public function up(): void
    {
        // TiDB: setting_id không có AUTO_INCREMENT (giống hotfix ở AuthController
        // cho library_cards.card_id) -> tự sinh id bằng max()+1 trong transaction.
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('system_settings')->whereIn('config_key', array_keys($this->keys))->delete();
    }
};
