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
        Schema::table('book_categories', function (Blueprint $table) {
            $table->foreign(['book_id'], 'fk_book_categories_book')->references(['book_id'])->on('books')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['category_id'], 'fk_book_categories_category')->references(['category_id'])->on('categories')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_categories', function (Blueprint $table) {
            $table->dropForeign('fk_book_categories_book');
            $table->dropForeign('fk_book_categories_category');
        });
    }
};
