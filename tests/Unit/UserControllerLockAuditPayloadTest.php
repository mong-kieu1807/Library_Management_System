<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\UserController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Module 7 mục 8 — kiểm tra suy luận khóa/mở khóa tài khoản từ status cũ/mới,
 * cùng payload before/after cho audit_logs. Pure PHP logic only, no database.
 */
class UserControllerLockAuditPayloadTest extends TestCase
{
    private function invoke(int $oldStatus, int $newStatus): ?array
    {
        $method = new ReflectionMethod(UserController::class, 'buildLockAuditPayload');
        $method->setAccessible(true);

        return $method->invoke(null, $oldStatus, $newStatus);
    }

    public function test_active_to_inactive_is_a_lock(): void
    {
        $result = $this->invoke(1, 0);

        $this->assertSame('lock', $result['action']);
        $this->assertSame(['status' => 'active'], $result['old_data']);
        $this->assertSame(['status' => 'locked'], $result['new_data']);
    }

    public function test_inactive_to_active_is_an_unlock(): void
    {
        $result = $this->invoke(0, 1);

        $this->assertSame('unlock', $result['action']);
        $this->assertSame(['status' => 'locked'], $result['old_data']);
        $this->assertSame(['status' => 'active'], $result['new_data']);
    }

    public function test_unchanged_status_returns_null(): void
    {
        $this->assertNull($this->invoke(1, 1));
        $this->assertNull($this->invoke(0, 0));
    }
}
