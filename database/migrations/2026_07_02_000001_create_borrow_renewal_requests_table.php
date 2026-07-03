<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('borrow_renewal_requests', function (Blueprint $table) {
            $table->increments('request_id');
            $table->unsignedInteger('borrow_id');
            $table->unsignedInteger('user_id');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->datetime('requested_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('borrow_renewal_requests');
    }
};
