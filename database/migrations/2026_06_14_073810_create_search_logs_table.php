<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('search_logs', function (Blueprint $table) {
            $table->integer('log_id', true);
            $table->integer('user_id')->nullable()->index('user_id');
            $table->string('keyword', 255);
            $table->json('filters')->nullable();
            $table->integer('result_count')->nullable()->default(0);
            $table->dateTime('searched_at')->nullable()->useCurrent();
            $table->string('ip_address', 45)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_logs');
    }
};
