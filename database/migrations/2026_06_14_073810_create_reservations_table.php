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
        Schema::create('reservations', function (Blueprint $table) {
            $table->integer('reservation_id', true);
            $table->integer('user_id')->index('fk_reservation_user');
            $table->integer('book_id')->index('fk_reservation_book');
            $table->integer('queue_position');
            $table->string('status', 50);
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
