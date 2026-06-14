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
        Schema::create('library_cards', function (Blueprint $table) {
            $table->integer('card_id', true);
            $table->integer('user_id')->index('fk_library_cards_user');
            $table->string('card_number', 50)->unique('card_number');
            $table->date('issue_date');
            $table->date('expiry_date');
            $table->integer('borrow_limit');
            $table->integer('max_borrow_days')->default(14);
            $table->tinyInteger('status')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_cards');
    }
};
