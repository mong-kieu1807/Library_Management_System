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
        Schema::create('book_copies', function (Blueprint $table) {
            $table->integer('copy_id', true);
            $table->integer('book_id')->index('fk_book_copies_book');
            $table->string('barcode', 100)->nullable()->unique('barcode');
            $table->string('status', 50)->nullable();
            $table->string('condition', 50)->nullable();
            $table->string('shelf_location', 100)->nullable();
            $table->date('acquisition_date')->nullable();
            $table->decimal('replacement_cost', 10)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_copies');
    }
};
