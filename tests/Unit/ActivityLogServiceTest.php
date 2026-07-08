<?php

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Services\ActivityLogService;
use Mockery;
use Tests\TestCase;

/**
 * Module 7 mục 8 (Log hoạt động hệ thống) — kiểm tra mỗi hàm tiện ích của
 * ActivityLogService map đúng action/module/description chuẩn hóa.
 * Mock hẳn log() (partial mock) để không đụng AuditLog::create() -> không cần DB.
 */
class ActivityLogServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_book_updated_maps_to_book_module_and_update_action(): void
    {
        $service = Mockery::mock(ActivityLogService::class)->makePartial();
        $service->shouldReceive('log')
            ->once()
            ->with(1, 'UPDATE', 'books', 42, ['title' => 'Cũ'], ['title' => 'Mới'], '127.0.0.1', 'BOOK', 'Cập nhật thông tin sách')
            ->andReturn(new AuditLog());

        $result = $service->bookUpdated(1, 42, ['title' => 'Cũ'], ['title' => 'Mới'], '127.0.0.1');

        $this->assertInstanceOf(AuditLog::class, $result);
    }

    public function test_setting_changed_description_includes_config_key(): void
    {
        $service = Mockery::mock(ActivityLogService::class)->makePartial();
        $service->shouldReceive('log')
            ->once()
            ->with(
                1,
                'UPDATE',
                'system_settings',
                5,
                ['config_key' => 'fine_per_day', 'config_value' => '2000'],
                ['config_key' => 'fine_per_day', 'config_value' => '3000'],
                '127.0.0.1',
                'SYSTEM_SETTING',
                'Cập nhật cấu hình: fine_per_day'
            )
            ->andReturn(new AuditLog());

        $result = $service->settingChanged(
            1,
            5,
            ['config_key' => 'fine_per_day', 'config_value' => '2000'],
            ['config_key' => 'fine_per_day', 'config_value' => '3000'],
            '127.0.0.1'
        );

        $this->assertInstanceOf(AuditLog::class, $result);
    }

    public function test_setting_changed_falls_back_to_generic_description_without_config_key(): void
    {
        $service = Mockery::mock(ActivityLogService::class)->makePartial();
        $service->shouldReceive('log')
            ->once()
            ->with(1, 'UPDATE', 'system_settings', 5, null, [], '127.0.0.1', 'SYSTEM_SETTING', 'Cập nhật cấu hình hệ thống')
            ->andReturn(new AuditLog());

        $result = $service->settingChanged(1, 5, null, [], '127.0.0.1');

        $this->assertInstanceOf(AuditLog::class, $result);
    }

    public function test_user_locked_maps_to_user_module_and_lock_action(): void
    {
        $service = Mockery::mock(ActivityLogService::class)->makePartial();
        $service->shouldReceive('log')
            ->once()
            ->with(9, 'LOCK', 'users', 7, ['status' => 'active'], ['status' => 'locked'], '127.0.0.1', 'USER', 'Khóa tài khoản')
            ->andReturn(new AuditLog());

        $result = $service->userLocked(9, 7, ['status' => 'active'], ['status' => 'locked'], '127.0.0.1');

        $this->assertInstanceOf(AuditLog::class, $result);
    }

    public function test_user_unlocked_maps_to_user_module_and_unlock_action(): void
    {
        $service = Mockery::mock(ActivityLogService::class)->makePartial();
        $service->shouldReceive('log')
            ->once()
            ->with(9, 'UNLOCK', 'users', 7, ['status' => 'locked'], ['status' => 'active'], '127.0.0.1', 'USER', 'Mở khóa tài khoản')
            ->andReturn(new AuditLog());

        $result = $service->userUnlocked(9, 7, ['status' => 'locked'], ['status' => 'active'], '127.0.0.1');

        $this->assertInstanceOf(AuditLog::class, $result);
    }

    public function test_holiday_created_updated_deleted_map_to_holiday_module(): void
    {
        $service = Mockery::mock(ActivityLogService::class)->makePartial();
        $service->shouldReceive('log')->once()
            ->with(1, 'CREATE', 'holidays', 3, null, ['name' => 'Tết'], null, 'HOLIDAY', 'Tạo ngày nghỉ mới')
            ->andReturn(new AuditLog());
        $service->shouldReceive('log')->once()
            ->with(1, 'UPDATE', 'holidays', 3, ['name' => 'Tết'], ['name' => 'Tết Nguyên Đán'], null, 'HOLIDAY', 'Cập nhật ngày nghỉ')
            ->andReturn(new AuditLog());
        $service->shouldReceive('log')->once()
            ->with(1, 'DELETE', 'holidays', 3, ['name' => 'Tết Nguyên Đán'], null, null, 'HOLIDAY', 'Xóa ngày nghỉ')
            ->andReturn(new AuditLog());

        $this->assertInstanceOf(AuditLog::class, $service->holidayCreated(1, 3, ['name' => 'Tết']));
        $this->assertInstanceOf(AuditLog::class, $service->holidayUpdated(1, 3, ['name' => 'Tết'], ['name' => 'Tết Nguyên Đán']));
        $this->assertInstanceOf(AuditLog::class, $service->holidayDeleted(1, 3, ['name' => 'Tết Nguyên Đán']));
    }

    public function test_email_template_updated_maps_to_email_template_module(): void
    {
        $service = Mockery::mock(ActivityLogService::class)->makePartial();
        $service->shouldReceive('log')
            ->once()
            ->with(1, 'UPDATE', 'email_templates', 2, ['subject' => 'Cũ'], ['subject' => 'Mới'], null, 'EMAIL_TEMPLATE', 'Cập nhật mẫu email')
            ->andReturn(new AuditLog());

        $result = $service->emailTemplateUpdated(1, 2, ['subject' => 'Cũ'], ['subject' => 'Mới']);

        $this->assertInstanceOf(AuditLog::class, $result);
    }

    public function test_backup_created_and_deleted_map_to_backup_module(): void
    {
        $service = Mockery::mock(ActivityLogService::class)->makePartial();
        $service->shouldReceive('log')->once()
            ->with(1, 'BACKUP', 'backups', 0, null, ['filename' => 'a.sql'], null, 'BACKUP', 'Tạo bản sao lưu')
            ->andReturn(new AuditLog());
        $service->shouldReceive('log')->once()
            ->with(1, 'DELETE', 'backups', 0, ['filename' => 'a.sql'], null, null, 'BACKUP', 'Xóa bản sao lưu')
            ->andReturn(new AuditLog());

        $this->assertInstanceOf(AuditLog::class, $service->backupCreated(1, ['filename' => 'a.sql']));
        $this->assertInstanceOf(AuditLog::class, $service->backupDeleted(1, ['filename' => 'a.sql']));
    }
}
