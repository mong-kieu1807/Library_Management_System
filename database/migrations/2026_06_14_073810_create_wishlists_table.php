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
        Schema::create('wishlists', function (Blueprint $table) {
            $table->integer('wishlist_id', true);
            $table->integer('user_id')->index('user_id');
            $table->integer('book_id')->index('book_id');
            $table->enum('list_type', ['favorite', 'reading', 'read']);
            $table->text('note')->nullable();
            $table->boolean('is_public')->nullable()->default(false);
            $table->string('share_token', 255)->nullable()->unique('share_token');
            $table->dateTime('created_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};
