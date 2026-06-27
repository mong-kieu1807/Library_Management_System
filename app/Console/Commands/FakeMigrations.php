<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FakeMigrations extends Command
{
    protected $signature = 'migrate:fake-all';
    protected $description = 'Mark all pending migrations as run without executing them';

    public function handle(): void
    {
        $migrations = [
            '2026_06_14_120941_create_sessions_table',
            '2026_06_15_065310_create_cache_table',
            '2026_06_17_000000_create_book_edit_histories_table',
            '2026_06_18_122439_create_personal_access_tokens_table',
            '2026_06_19_000001_create_password_reset_tokens_table',
            '2026_06_19_100000_create_change_password_otps_table',
            '2026_06_21_072739_add_is_active_to_authors_table',
            '2026_06_22_052455_add_google2fa_secret_to_users_table',
            '2026_06_22_052522_add_librarian_level_to_users_table',
        ];

        foreach ($migrations as $migration) {
            $exists = DB::table('migrations')->where('migration', $migration)->exists();
            if (!$exists) {
                DB::table('migrations')->insert(['migration' => $migration, 'batch' => 1]);
                $this->info("Faked: $migration");
            } else {
                $this->warn("Already exists: $migration");
            }
        }

        $this->info('Done. Total: ' . DB::table('migrations')->count() . ' records.');
    }
}
