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
        Schema::create('chatbot_conversations', function (Blueprint $table) {
            $table->integer('conversation_id', true);
            $table->integer('user_id')->index('user_id');
            $table->string('title', 255)->nullable();
            $table->text('context_summary')->nullable();
            $table->enum('status', ['active', 'closed'])->nullable()->default('active');
            $table->dateTime('started_at')->nullable()->useCurrent();
            $table->dateTime('last_message_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_conversations');
    }
};
