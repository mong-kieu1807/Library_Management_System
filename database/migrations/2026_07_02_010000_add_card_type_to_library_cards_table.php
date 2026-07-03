<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('library_cards', function (Blueprint $table) {
            $table->enum('card_type', ['regular', 'priority'])
                ->default('regular')
                ->after('max_borrow_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('library_cards', function (Blueprint $table) {
            $table->dropColumn('card_type');
        });
    }
};
