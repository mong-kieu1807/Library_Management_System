<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_edit_histories', function (Blueprint $table) {
            $table->integer('history_id', true);
            $table->integer('book_id')->index('idx_book_edit_histories_book_id');
            $table->integer('edited_by')->index('idx_book_edit_histories_edited_by');
            $table->string('field_name', 100);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('edit_reason')->nullable();
            $table->dateTime('edited_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();

            $table->foreign('book_id', 'fk_book_edit_histories_book')
                ->references('book_id')
                ->on('books')
                ->onUpdate('restrict')
                ->onDelete('cascade');

            $table->foreign('edited_by', 'fk_book_edit_histories_user')
                ->references('user_id')
                ->on('users')
                ->onUpdate('restrict')
                ->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_edit_histories');
    }
};
