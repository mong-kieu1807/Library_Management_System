<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lưu hạn trả riêng cho từng bản sao sau khi được gia hạn.
// borrow_transactions.due_date dùng chung cho cả giao dịch (nhiều sách),
// không đủ để tách hạn trả độc lập theo từng cuốn. Nullable = chưa gia
// hạn lần nào -> mọi nơi đọc hạn trả dùng
// COALESCE(bd.renewed_due_date, bt.due_date) để không đổi dữ liệu cũ.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('borrow_details', function (Blueprint $table) {
            $table->date('renewed_due_date')->nullable()->after('renew_count');
        });
    }

    public function down(): void
    {
        Schema::table('borrow_details', function (Blueprint $table) {
            $table->dropColumn('renewed_due_date');
        });
    }
};
