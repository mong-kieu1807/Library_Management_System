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
        Schema::table('fines', function (Blueprint $table) {
            $table->foreign(['borrow_id'], 'fk_fine_borrow')->references(['borrow_id'])->on('borrow_transactions')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['user_id'], 'fk_fine_user')->references(['user_id'])->on('users')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fines', function (Blueprint $table) {
            $table->dropForeign('fk_fine_borrow');
            $table->dropForeign('fk_fine_user');
        });
    }
};
