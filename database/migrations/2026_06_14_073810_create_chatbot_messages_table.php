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
        Schema::create('chatbot_messages', function (Blueprint $table) {
            $table->integer('message_id', true);
            $table->integer('conversation_id')->index('conversation_id');
            $table->integer('referenced_book_id')->nullable()->index('referenced_book_id');
            $table->enum('sender_type', ['user', 'assistant', 'system']);
            $table->longText('message_content');
            $table->string('intent', 100)->nullable();
            $table->integer('tokens_used')->nullable()->default(0);
            $table->integer('response_time_ms')->nullable();
            $table->dateTime('created_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
    }
};
