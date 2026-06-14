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
        Schema::create('recommendation_logs', function (Blueprint $table) {
            $table->integer('recommendation_id', true);
            $table->integer('user_id')->index('user_id');
            $table->integer('source_book_id')->nullable()->index('source_book_id');
            $table->integer('recommended_book_id')->index('recommended_book_id');
            $table->enum('recommendation_type', ['similar_author', 'same_category', 'collaborative', 'ai_chatbot', 'wishlist_based']);
            $table->decimal('score', 5)->nullable();
            $table->boolean('is_clicked')->nullable()->default(false);
            $table->boolean('is_borrowed')->nullable()->default(false);
            $table->dateTime('created_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recommendation_logs');
    }
};
