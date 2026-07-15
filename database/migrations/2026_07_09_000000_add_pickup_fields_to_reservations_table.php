<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Module 4 refactor: reservations giờ tách 2 luồng (counter / online) và
// không còn nhảy thẳng Pending -> Borrowing. Xem app/Http/Controllers/Admin/ReservationController.php.
//
// Backfill dữ liệu cũ:
//   - 'waiting' -> 'pending' + pickup_type='online' (hệ thống cũ chỉ tạo reservation
//     khi hết bản sao, nên mọi hàng cũ vốn dĩ là 'online').
//   - 'ready' -> 'pending' + pickup_type='online', xoá notified_at/expired_at:
//     hệ thống cũ không lưu copy_id bị khoá riêng cho từng reservation nên không thể
//     khôi phục chính xác sang 'ready_for_pickup' — đưa lại đầu hàng chờ để thủ thư
//     xác nhận lại bằng nút "Xác nhận có sách" theo luồng mới.
//   - 'converted' -> 'completed' (trạng thái cuối, không cần copy_id).
//   - 'expired'/'cancelled' giữ nguyên, pickup_type='online' mặc định (vô hại, đã kết thúc).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('pickup_type', 20)->default('counter')->after('book_id');
            $table->integer('copy_id')->nullable()->after('pickup_type');
            $table->index('copy_id');
            $table->dateTime('ready_at')->nullable()->after('notified_at');
            $table->dateTime('pickup_deadline')->nullable()->after('ready_at');
            // counter reservations don't queue, so queue_position must allow null
            $table->integer('queue_position')->nullable()->change();
        });

        DB::table('reservations')->where('status', 'waiting')->update([
            'status'      => 'pending',
            'pickup_type' => 'online',
        ]);

        DB::table('reservations')->where('status', 'ready')->update([
            'status'      => 'pending',
            'pickup_type' => 'online',
            'notified_at' => null,
            'expired_at'  => null,
        ]);

        DB::table('reservations')->where('status', 'converted')->update([
            'status' => 'completed',
        ]);

        DB::table('reservations')->whereIn('status', ['expired', 'cancelled'])->update([
            'pickup_type' => 'online',
        ]);
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['copy_id']);
            $table->dropColumn(['pickup_type', 'copy_id', 'ready_at', 'pickup_deadline']);
        });
    }
};
