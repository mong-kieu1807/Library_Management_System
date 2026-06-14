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
        Schema::create('card_renewal_requests', function (Blueprint $table) {
            $table->integer('request_id', true);
            $table->integer('card_id')->index('card_id');
            $table->integer('user_id')->index('user_id');
            $table->date('requested_expiry_date');
            $table->enum('status', ['pending', 'approved', 'rejected'])->nullable()->default('pending');
            $table->integer('reviewed_by')->nullable()->index('reviewed_by');
            $table->string('review_note', 255)->nullable();
            $table->dateTime('requested_at')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('card_renewal_requests');
    }
};
