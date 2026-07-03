<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Module 7 mục 8 (Log hoạt động hệ thống) yêu cầu định dạng thống nhất gồm
     * module/action/description bên cạnh before/after đã có sẵn (chính là
     * old_data/new_data hiện tại — không đổi tên 2 cột này để tránh phá vỡ dữ liệu
     * 51 dòng log đã ghi từ Phase 5-6A). Chỉ thêm 2 cột còn thiếu thật sự:
     *   - module: nhóm chức năng viết hoa (BOOK, SYSTEM_SETTING, USER, BORROW,
     *     RESERVATION, HOLIDAY, EMAIL_TEMPLATE, BACKUP) — khác với table_name
     *     (tên bảng CSDL thật) đã có, module là tên nhóm hiển thị cho người dùng.
     *   - description: mô tả tiếng Việt ngắn gọn (vd "Cập nhật sách").
     * Cả 2 đều nullable để không phá 51 dòng log cũ (giữ giá trị NULL, không backfill).
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('module', 50)->nullable()->after('action');
            $table->string('description', 255)->nullable()->after('new_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn(['module', 'description']);
        });
    }
};
