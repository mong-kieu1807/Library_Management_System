<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Bổ sung copy_id để xác định đúng cuốn sách được yêu cầu gia hạn —
// borrow_id chỉ đại diện cho cả giao dịch (có thể gồm nhiều bản sao).
// Nullable + không FK: giữ nguyên convention hiện có của bảng này
// (borrow_id/user_id cũng không có FK), không đổi dữ liệu cũ.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrow_renewal_requests', function (Blueprint $table) {
            $table->integer('copy_id')->nullable()->after('borrow_id');
            $table->index('copy_id');
        });
    }

    public function down(): void
    {
        Schema::table('borrow_renewal_requests', function (Blueprint $table) {
            $table->dropIndex(['copy_id']);
            $table->dropColumn('copy_id');
        });
    }
};
