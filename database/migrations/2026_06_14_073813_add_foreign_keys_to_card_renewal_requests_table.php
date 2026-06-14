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
        Schema::table('card_renewal_requests', function (Blueprint $table) {
            $table->foreign(['card_id'], 'card_renewal_requests_ibfk_1')->references(['card_id'])->on('library_cards')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'], 'card_renewal_requests_ibfk_2')->references(['user_id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['reviewed_by'], 'card_renewal_requests_ibfk_3')->references(['user_id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('card_renewal_requests', function (Blueprint $table) {
            $table->dropForeign('card_renewal_requests_ibfk_1');
            $table->dropForeign('card_renewal_requests_ibfk_2');
            $table->dropForeign('card_renewal_requests_ibfk_3');
        });
    }
};
