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
        Schema::table('reservations', function (Blueprint $table) {
            $table->foreign(['book_id'], 'fk_reservation_book')->references(['book_id'])->on('books')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'], 'fk_reservation_user')->references(['user_id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropForeign('fk_reservation_book');
            $table->dropForeign('fk_reservation_user');
        });
    }
};
