<?php

namespace Tests\Feature;

use App\Services\BookService;
use Tests\TestCase;

class AIChatAvailabilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.mock_mode' => true]);
        config(['cache.default' => 'array']);
    }

    private function fakeAvailability(array $overrides = []): array
    {
        return array_merge([
            'book_id'          => 1,
            'title'            => 'Atomic Habits',
            'total_copies'     => 5,
            'available_copies' => 3,
            'borrowed_copies'  => 2,
            'reserved_copies'  => 0,
            'is_available'     => true,
        ], $overrides);
    }

    private function fakeSearch(int $bookId, string $title): array
    {
        return [
            [
                'book_id'          => $bookId,
                'title'            => $title,
                'author'           => 'James Clear',
                'isbn'             => '978-0000000001',
                'available_copies' => 3,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // 1. Availability by title — chains search → check_book_availability
    // -------------------------------------------------------------------------

    public function test_availability_by_title_chains_search_then_check(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(10, 'Atomic Habits'));
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(10)
                 ->andReturn($this->fakeAvailability(['book_id' => 10, 'title' => 'Atomic Habits']));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách Atomic Habits còn không?',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('[MOCK]', $reply);
        $this->assertStringContainsString('Atomic Habits', $reply);
    }

    // -------------------------------------------------------------------------
    // 2. "Còn bản nào để mượn không?" — chains search → check_book_availability
    // -------------------------------------------------------------------------

    public function test_con_ban_nao_de_muon_chains_to_check(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(7, 'Mắt Biếc'));
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(7)
                 ->andReturn($this->fakeAvailability(['book_id' => 7, 'title' => 'Mắt Biếc']));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Mắt Biếc còn bản nào để mượn không?',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
        $this->assertStringContainsString('Mắt Biếc', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 3. Direct check by book_id — no search needed
    // -------------------------------------------------------------------------

    public function test_availability_by_book_id_direct(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(5)
                 ->andReturn($this->fakeAvailability(['book_id' => 5, 'title' => 'Nhà Giả Kim']));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách ID 5 còn không?',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('[MOCK]', $reply);
        $this->assertStringContainsString('Nhà Giả Kim', $reply);
    }

    // -------------------------------------------------------------------------
    // 4. Available — reply shows positive message with count
    // -------------------------------------------------------------------------

    public function test_available_reply_shows_available_count(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(1)
                 ->andReturn($this->fakeAvailability(['available_copies' => 4, 'total_copies' => 6]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách ID 1 còn không?',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('4', $reply);
        $this->assertStringContainsString('6', $reply);
    }

    // -------------------------------------------------------------------------
    // 5. Not available — reply shows 0 available
    // -------------------------------------------------------------------------

    public function test_not_available_reply_shows_zero_message(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(2)
                 ->andReturn($this->fakeAvailability([
                     'book_id'          => 2,
                     'title'            => 'Đắc Nhân Tâm',
                     'total_copies'     => 3,
                     'available_copies' => 0,
                     'borrowed_copies'  => 3,
                     'reserved_copies'  => 0,
                     'is_available'     => false,
                 ]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách ID 2 có thể mượn không?',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('không có bản nào', $reply);
        $this->assertStringContainsString('Đắc Nhân Tâm', $reply);
    }

    // -------------------------------------------------------------------------
    // 6. Not available + borrowed count shown
    // -------------------------------------------------------------------------

    public function test_not_available_shows_borrowed_count(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(3)
                 ->andReturn($this->fakeAvailability([
                     'book_id'          => 3,
                     'title'            => 'Sapiens',
                     'total_copies'     => 4,
                     'available_copies' => 0,
                     'borrowed_copies'  => 4,
                     'reserved_copies'  => 0,
                     'is_available'     => false,
                 ]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách ID 3 còn không?',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('4', $reply);
        $this->assertStringContainsString('mượn', $reply);
    }

    // -------------------------------------------------------------------------
    // 7. Not available + reserved count shown
    // -------------------------------------------------------------------------

    public function test_not_available_shows_reserved_count(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(4)
                 ->andReturn($this->fakeAvailability([
                     'book_id'          => 4,
                     'title'            => 'Homo Deus',
                     'total_copies'     => 2,
                     'available_copies' => 0,
                     'borrowed_copies'  => 1,
                     'reserved_copies'  => 1,
                     'is_available'     => false,
                 ]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách ID 4 còn bản nào không?',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('đặt trước', $reply);
    }

    // -------------------------------------------------------------------------
    // 8. Not available + suggestion to reserve shown
    // -------------------------------------------------------------------------

    public function test_not_available_suggests_reserve(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(6)
                 ->andReturn($this->fakeAvailability([
                     'book_id'          => 6,
                     'title'            => 'Think and Grow Rich',
                     'total_copies'     => 2,
                     'available_copies' => 0,
                     'borrowed_copies'  => 2,
                     'reserved_copies'  => 0,
                     'is_available'     => false,
                 ]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách ID 6 còn không?',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('đặt trước', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 9. Search returns no results → not-found text, no check_book_availability
    // -------------------------------------------------------------------------

    public function test_availability_search_no_results_returns_not_found(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách Không Tồn Tại XYZ còn không?',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $reply = $response->json('reply');
        $this->assertStringContainsString('[MOCK]', $reply);
    }

    // -------------------------------------------------------------------------
    // 10. "Có sẵn không?" keyword — triggers availability chain
    // -------------------------------------------------------------------------

    public function test_co_san_khong_keyword_chains_availability(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(11, 'Clean Code'));
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(11)
                 ->andReturn($this->fakeAvailability(['book_id' => 11, 'title' => 'Clean Code', 'available_copies' => 1]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Clean Code có sẵn không?',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('Clean Code', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 11. "Hết sách chưa?" keyword — triggers availability chain
    // -------------------------------------------------------------------------

    public function test_het_sach_chua_keyword_chains_availability(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(12, 'Dune'));
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(12)
                 ->andReturn($this->fakeAvailability([
                     'book_id' => 12, 'title' => 'Dune',
                     'available_copies' => 0, 'is_available' => false,
                 ]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách Dune hết sách chưa?',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('Dune', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 12. Availability reply has [MOCK] tag (not from Gemini)
    // -------------------------------------------------------------------------

    public function test_availability_reply_has_mock_tag(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(8)
                 ->andReturn($this->fakeAvailability(['book_id' => 8, 'title' => 'The Lean Startup']));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách ID 8 còn không?',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 13. Backward compat: search_books still works after AI.3
    // -------------------------------------------------------------------------

    public function test_search_books_backward_compat_after_ai3(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Tìm sách lịch sử Việt Nam',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 14. Backward compat: get_book_detail intro chain still works after AI.3
    // -------------------------------------------------------------------------

    public function test_intro_chain_backward_compat_after_ai3(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(15, 'Người Đua Diều'));
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(15)
                 ->andReturn([
                     'book_id'          => 15,
                     'title'            => 'Người Đua Diều',
                     'isbn'             => '978-9999999999',
                     'description'      => 'Câu chuyện xúc động về tình bạn và sự cứu chuộc.',
                     'publish_year'     => 2003,
                     'language'         => 'vi',
                     'pages'            => 400,
                     'avg_rating'       => 4.7,
                     'total_reviews'    => 200,
                     'publisher'        => 'NXB Văn học',
                     'available_copies' => 2,
                     'authors'          => ['Khaled Hosseini'],
                     'categories'       => ['Tiểu thuyết'],
                 ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách Người Đua Diều',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('[MOCK]', $reply);
        $this->assertStringContainsString('Người Đua Diều', $reply);
        $this->assertStringContainsString('Khaled Hosseini', $reply);
    }
}
