<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\BookController;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Module 7 mục 8 — kiểm tra bước gộp $changes (đã tính sẵn cho book_edit_histories)
 * thành before/after cho audit_logs khi sửa sách. Pure PHP logic only, no database.
 */
class BookControllerAuditPayloadTest extends TestCase
{
    private function invoke(array $changes): array
    {
        $method = new ReflectionMethod(BookController::class, 'buildBookAuditPayload');
        $method->setAccessible(true);

        return $method->invoke(null, $changes);
    }

    public function test_single_field_change_produces_matching_old_and_new_maps(): void
    {
        $changes = [
            ['field_name' => 'title', 'old_value' => 'Mắt biếc', 'new_value' => 'Mắt biếc (Tái bản)'],
        ];

        $result = $this->invoke($changes);

        $this->assertSame(['title' => 'Mắt biếc'], $result['old']);
        $this->assertSame(['title' => 'Mắt biếc (Tái bản)'], $result['new']);
    }

    public function test_multiple_field_changes_are_all_captured(): void
    {
        $changes = [
            ['field_name' => 'title', 'old_value' => 'A', 'new_value' => 'B'],
            ['field_name' => 'publisher_id', 'old_value' => 'NXB Trẻ', 'new_value' => 'NXB Kim Đồng'],
            ['field_name' => 'authors', 'old_value' => ['Nguyễn A'], 'new_value' => ['Nguyễn A', 'Trần B']],
        ];

        $result = $this->invoke($changes);

        $this->assertSame(['title' => 'A', 'publisher_id' => 'NXB Trẻ', 'authors' => ['Nguyễn A']], $result['old']);
        $this->assertSame(['title' => 'B', 'publisher_id' => 'NXB Kim Đồng', 'authors' => ['Nguyễn A', 'Trần B']], $result['new']);
    }

    public function test_empty_changes_produce_empty_maps(): void
    {
        $result = $this->invoke([]);

        $this->assertSame(['old' => [], 'new' => []], $result);
    }
}
