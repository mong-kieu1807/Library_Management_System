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
        Schema::table('book_authors', function (Blueprint $table) {
            $table->foreign(['author_id'], 'fk_book_authors_author')->references(['author_id'])->on('authors')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['book_id'], 'fk_book_authors_book')->references(['book_id'])->on('books')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_authors', function (Blueprint $table) {
            $table->dropForeign('fk_book_authors_author');
            $table->dropForeign('fk_book_authors_book');
        });
    }
};
