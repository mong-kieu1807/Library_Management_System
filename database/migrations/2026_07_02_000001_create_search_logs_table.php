<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bảng đã tồn tại trong DB với cấu trúc:
        // log_id, user_id, keyword, filters, result_count, searched_at, ip_address
        if (Schema::hasTable('search_logs')) {
            return;
        }

        Schema::create('search_logs', function (Blueprint $table) {
            $table->id('log_id');
            $table->unsignedInteger('user_id')->nullable()->index();
            $table->string('keyword', 255);
            $table->longText('filters')->nullable();
            $table->integer('result_count')->default(0);
            $table->dateTime('searched_at');
            $table->string('ip_address', 45)->nullable();

            $table->index('keyword');
            $table->index(['result_count', 'searched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
