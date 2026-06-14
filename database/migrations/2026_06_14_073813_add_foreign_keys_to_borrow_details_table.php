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
        Schema::table('borrow_details', function (Blueprint $table) {
            $table->foreign(['borrow_id'], 'fk_borrow_details_borrow')->references(['borrow_id'])->on('borrow_transactions')->onUpdate('restrict')->onDelete('restrict');
            $table->foreign(['copy_id'], 'fk_borrow_details_copy')->references(['copy_id'])->on('book_copies')->onUpdate('restrict')->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrow_details', function (Blueprint $table) {
            $table->dropForeign('fk_borrow_details_borrow');
            $table->dropForeign('fk_borrow_details_copy');
        });
    }
};
