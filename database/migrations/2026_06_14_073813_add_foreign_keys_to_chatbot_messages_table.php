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
        Schema::table('chatbot_messages', function (Blueprint $table) {
            $table->foreign(['conversation_id'], 'chatbot_messages_ibfk_1')->references(['conversation_id'])->on('chatbot_conversations')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['referenced_book_id'], 'chatbot_messages_ibfk_2')->references(['book_id'])->on('books')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatbot_messages', function (Blueprint $table) {
            $table->dropForeign('chatbot_messages_ibfk_1');
            $table->dropForeign('chatbot_messages_ibfk_2');
        });
    }
};
