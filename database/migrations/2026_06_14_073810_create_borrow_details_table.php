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
        Schema::create('borrow_details', function (Blueprint $table) {
            $table->integer('borrow_id');
            $table->integer('copy_id')->index('fk_borrow_details_copy');
            $table->date('return_date')->nullable();
            $table->integer('renew_count')->nullable()->default(0);
            $table->string('condition_return', 50)->nullable();
            $table->decimal('fine_amount', 10)->nullable();

            $table->primary(['borrow_id', 'copy_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('borrow_details');
    }
};
