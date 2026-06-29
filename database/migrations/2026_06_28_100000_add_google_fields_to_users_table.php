<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id', 255)->nullable()->unique()->after('google2fa_secret');
            $table->timestamp('email_verified_at')->nullable()->after('google_id');
        });
    }

    public function down(): void
    {
        // TiDB: drop unique index first, then drop columns (separate statements)
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'email_verified_at']);
        });
    }
};
