<?php

namespace Tests\Feature;

use App\Services\BookService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AIChatBookSummaryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.mock_mode' => true]);
        config(['cache.default' => 'array']);
    }

    private function fakeBook(array $overrides = []): array
    {
        return array_merge([
            'book_id'          => 1,
            'title'            => 'Đắc Nhân Tâm',
            'isbn'             => '978-1234567890',
            'description'      => 'Cuốn sách nổi tiếng về nghệ thuật giao tiếp và ứng xử với con người.',
            'publish_year'     => 2020,
            'language'         => 'vi',
            'pages'            => 320,
            'avg_rating'       => 4.8,
            'total_reviews'    => 150,
            'publisher'        => 'NXB Tổng hợp',
            'available_copies' => 3,
            'authors'          => ['Dale Carnegie'],
            'categories'       => ['Kỹ năng sống'],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // 1. Intro by name, no results → search_books called, no chain, returns not-found text
    // -------------------------------------------------------------------------

    public function test_intro_by_title_calls_search_books(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách Đắc Nhân Tâm',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
    }

    // -------------------------------------------------------------------------
    // 1b. Intro by name + book found → full 3-round chain: search → get_book_detail → intro
    // -------------------------------------------------------------------------

    public function test_intro_by_title_chains_to_get_book_detail_when_book_found(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn([
                     ['book_id' => 42, 'title' => 'Mắt Biếc', 'author' => 'Nguyễn Nhật Ánh', 'isbn' => '978-0000000042', 'available_copies' => 2],
                 ]);
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(42)
                 ->andReturn($this->fakeBook([
                     'book_id' => 42,
                     'title'   => 'Mắt Biếc',
                     'authors' => ['Nguyễn Nhật Ánh'],
                 ]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách Mắt Biếc',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('[MOCK]', $reply);
        $this->assertStringContainsString('Mắt Biếc', $reply);
        $this->assertStringContainsString('Nguyễn Nhật Ánh', $reply);
    }

    // -------------------------------------------------------------------------
    // 1c. "Tóm tắt" + found → chain to get_book_detail
    // -------------------------------------------------------------------------

    public function test_tomtat_keyword_chains_to_get_book_detail_when_found(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn([
                     ['book_id' => 7, 'title' => 'Mắt Biếc', 'author' => 'Nguyễn Nhật Ánh', 'isbn' => '...', 'available_copies' => 1],
                 ]);
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(7)
                 ->andReturn($this->fakeBook(['book_id' => 7, 'title' => 'Mắt Biếc']));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Tóm tắt Mắt Biếc',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 2. Intro with explicit book_id → get_book_detail called
    // -------------------------------------------------------------------------

    public function test_intro_with_book_id_calls_get_book_detail(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(5)
                 ->andReturn($this->fakeBook(['book_id' => 5]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách ID 5',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 3. "Tóm tắt" keyword + ID → get_book_detail
    // -------------------------------------------------------------------------

    public function test_summary_keyword_with_id_calls_get_book_detail(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(3)
                 ->andReturn($this->fakeBook(['book_id' => 3]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Tóm tắt cuốn sách ID 3',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 4. Mock Round 2 for get_book_detail generates intro-style text
    // -------------------------------------------------------------------------

    public function test_mock_round2_get_book_detail_generates_intro_text(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(1)
                 ->andReturn($this->fakeBook());
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách ID 1',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('[MOCK]', $reply);
        $this->assertStringContainsString('Đắc Nhân Tâm', $reply);
        $this->assertStringContainsString('Dale Carnegie', $reply);
    }

    // -------------------------------------------------------------------------
    // 5. Intro reply contains title, author, categories, publisher
    // -------------------------------------------------------------------------

    public function test_intro_reply_contains_book_metadata(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(2)
                 ->andReturn($this->fakeBook([
                     'book_id'    => 2,
                     'title'      => 'Nhà Giả Kim',
                     'authors'    => ['Paulo Coelho'],
                     'categories' => ['Tiểu thuyết'],
                     'publisher'  => 'NXB Văn học',
                 ]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Cho tôi biết về sách ID 2',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('Nhà Giả Kim', $reply);
        $this->assertStringContainsString('Paulo Coelho', $reply);
        $this->assertStringContainsString('Tiểu thuyết', $reply);
        $this->assertStringContainsString('NXB Văn học', $reply);
    }

    // -------------------------------------------------------------------------
    // 6. Empty description → no fabrication, explicit message shown
    // -------------------------------------------------------------------------

    public function test_empty_description_shows_no_fabrication_message(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(7)
                 ->andReturn($this->fakeBook([
                     'book_id'     => 7,
                     'description' => null,
                 ]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách ID 7',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('Chưa có thông tin mô tả', $reply);
    }

    // -------------------------------------------------------------------------
    // 7. Available copies shown in intro
    // -------------------------------------------------------------------------

    public function test_intro_includes_available_copies(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(1)
                 ->andReturn($this->fakeBook(['available_copies' => 5]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách ID 1',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('5', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 8. Rating shown if non-zero
    // -------------------------------------------------------------------------

    public function test_intro_includes_rating_when_available(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(1)
                 ->andReturn($this->fakeBook(['avg_rating' => 4.8, 'total_reviews' => 150]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách ID 1',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('4.8', $reply);
        $this->assertStringContainsString('150', $reply);
    }

    // -------------------------------------------------------------------------
    // 9. Zero rating → rating line not shown (no "0.0/5")
    // -------------------------------------------------------------------------

    public function test_intro_zero_rating_not_shown(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(1)
                 ->andReturn($this->fakeBook(['avg_rating' => 0.0, 'total_reviews' => 0]));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách ID 1',
        ]);

        $response->assertStatus(200);
        $this->assertStringNotContainsString('0.0/5', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 10. Language label mapped correctly (vi → Tiếng Việt, en → Tiếng Anh)
    // -------------------------------------------------------------------------

    public function test_intro_language_label_mapped(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(1)
                 ->andReturn($this->fakeBook(['language' => 'en']));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách ID 1',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('Tiếng Anh', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 11. Session memory saved after book intro
    // -------------------------------------------------------------------------

    public function test_session_memory_saved_after_intro(): void
    {
        $sid = 'intro-session-test-abc';

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(1)
                 ->andReturn($this->fakeBook());
        });

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Giới thiệu sách ID 1',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history = Cache::get('ai_session_' . $sid);
        $this->assertNotNull($history);
        $this->assertSame('user',      $history[0]['role']);
        $this->assertSame('Giới thiệu sách ID 1', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
    }

    // -------------------------------------------------------------------------
    // 12. Follow-up question after intro uses session context
    // -------------------------------------------------------------------------

    public function test_follow_up_after_intro_uses_session_context(): void
    {
        $sid = 'intro-followup-session-xyz';

        Cache::put('ai_session_' . $sid, [
            ['role' => 'user',      'content' => 'Giới thiệu sách ID 1', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => '[MOCK] **Đắc Nhân Tâm**...', 'timestamp' => time()],
        ], 120);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Còn sách nào tương tự không?',
            'session_id' => $sid,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);

        $history = Cache::get('ai_session_' . $sid);
        $this->assertCount(4, $history);
        $this->assertSame('Còn sách nào tương tự không?', $history[2]['content']);
    }

    // -------------------------------------------------------------------------
    // 13. Backward compat: search_books and get_library_policy still work
    // -------------------------------------------------------------------------

    public function test_search_books_backward_compat_after_ai2(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Tìm sách lập trình Python',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    public function test_get_library_policy_backward_compat_after_ai2(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getLibrarySettings')->once()->andReturn([
                'borrow_limit'    => 5,
                'max_borrow_days' => 14,
                'fine_per_day'    => 2000,
            ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Phí phạt quá hạn là bao nhiêu?',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }
}
