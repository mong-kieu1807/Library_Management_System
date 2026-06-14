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
        Schema::create('borrow_transactions', function (Blueprint $table) {
            $table->integer('borrow_id', true);
            $table->integer('user_id')->index('fk_borrow_user');
            $table->integer('librarian_id')->index('fk_borrow_librarian');
            $table->date('borrow_date');
            $table->date('due_date');
            $table->string('status', 50)->index('idx_borrow_status');
            $table->integer('renew_count')->nullable()->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrow_transactions');
    }
};
