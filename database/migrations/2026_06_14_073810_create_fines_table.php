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
        Schema::create('fines', function (Blueprint $table) {
            $table->integer('fine_id', true);
            $table->integer('user_id')->index('fk_fine_user');
            $table->integer('borrow_id')->index('fk_fine_borrow');
            $table->decimal('amount', 10);
            $table->string('reason', 255);
            $table->string('status', 50);
            $table->dateTime('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fines');
    }
};
