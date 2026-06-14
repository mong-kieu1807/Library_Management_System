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
        Schema::create('books', function (Blueprint $table) {
            $table->integer('book_id', true);
            $table->string('title', 255)->index('idx_books_title');
            $table->string('isbn', 20)->nullable()->index('idx_books_isbn');
            $table->integer('publisher_id')->nullable()->index('fk_books_publisher');
            $table->date('publish_date')->nullable();
            $table->integer('publish_year')->nullable();
            $table->string('edition', 50)->nullable();
            $table->string('language', 50)->nullable();
            $table->integer('pages')->nullable();
            $table->string('dimensions', 100)->nullable();
            $table->string('cover_type', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('cover_image', 255)->nullable();
            $table->decimal('avg_rating', 2, 1)->nullable()->default(0);
            $table->integer('total_reviews')->nullable()->default(0);
            $table->decimal('replacement_cost', 10)->nullable()->default(0);
            $table->boolean('is_featured')->nullable()->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->unique(['isbn'], 'isbn');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
