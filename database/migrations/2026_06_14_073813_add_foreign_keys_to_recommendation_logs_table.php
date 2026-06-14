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
        Schema::table('recommendation_logs', function (Blueprint $table) {
            $table->foreign(['user_id'], 'recommendation_logs_ibfk_1')->references(['user_id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['source_book_id'], 'recommendation_logs_ibfk_2')->references(['book_id'])->on('books')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['recommended_book_id'], 'recommendation_logs_ibfk_3')->references(['book_id'])->on('books')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recommendation_logs', function (Blueprint $table) {
            $table->dropForeign('recommendation_logs_ibfk_1');
            $table->dropForeign('recommendation_logs_ibfk_2');
            $table->dropForeign('recommendation_logs_ibfk_3');
        });
    }
};
