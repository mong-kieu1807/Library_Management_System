<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Bổ sung trạng thái "cancelled" để Reader tự hủy yêu cầu gia hạn (thẻ + sách)
// khi còn Pending — tận dụng enum status hiện có, không tạo bảng/cột mới.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE card_renewal_requests MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE borrow_renewal_requests MODIFY status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE card_renewal_requests MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        DB::statement("ALTER TABLE borrow_renewal_requests MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
    }
};
