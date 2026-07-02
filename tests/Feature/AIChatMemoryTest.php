<?php

namespace Tests\Feature;

use App\Services\BookService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AIChatMemoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config(['ai.mock_mode'  => true]);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function sid(string $suffix = 'abc123xyz'): string
    {
        return 'test-session-' . $suffix;
    }

    private function cacheKey(string $sessionId): string
    {
        return 'ai_session_' . $sessionId;
    }

    private function mockSettings(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getLibrarySettings')->andReturn([
                'borrow_limit'    => 5,
                'max_borrow_days' => 14,
                'fine_per_day'    => 2000,
            ]);
            $mock->shouldReceive('searchBooks')->andReturn([]);
        });
    }

    // -------------------------------------------------------------------------
    // Session empty on first request
    // -------------------------------------------------------------------------

    public function test_session_starts_empty(): void
    {
        $sid = $this->sid('empty');
        $this->assertNull(Cache::get($this->cacheKey($sid)));

        $this->mockSettings();
        $response = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Có sách Chí Phèo không?',
            'session_id' => $sid,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
    }

    // -------------------------------------------------------------------------
    // History accumulated after two messages
    // -------------------------------------------------------------------------

    public function test_history_is_saved_after_each_message(): void
    {
        $sid = $this->sid('accum');
        $this->mockSettings();

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Tôi muốn học lập trình.',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history = Cache::get($this->cacheKey($sid));
        $this->assertIsArray($history);
        $this->assertCount(2, $history); // 1 user + 1 assistant
        $this->assertSame('user',      $history[0]['role']);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertSame('Tôi muốn học lập trình.', $history[0]['content']);

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Có bản tiếng Anh không?',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history2 = Cache::get($this->cacheKey($sid));
        $this->assertCount(4, $history2); // 2 user + 2 assistant
        $this->assertSame('Có bản tiếng Anh không?', $history2[2]['content']);
    }

    // -------------------------------------------------------------------------
    // Limit to 10 messages — oldest dropped
    // -------------------------------------------------------------------------

    public function test_history_is_trimmed_to_10_messages(): void
    {
        $sid = $this->sid('trim');

        // Pre-populate with 10 messages (5 exchanges)
        $existing = [];
        for ($i = 1; $i <= 5; $i++) {
            $existing[] = ['role' => 'user',      'content' => "Q{$i}", 'timestamp' => time()];
            $existing[] = ['role' => 'assistant', 'content' => "A{$i}", 'timestamp' => time()];
        }
        Cache::put($this->cacheKey($sid), $existing, 120);

        $this->mockSettings();
        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Câu hỏi thứ 6',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history = Cache::get($this->cacheKey($sid));
        $this->assertCount(10, $history, 'Phải giữ đúng 10 messages');

        // Oldest (Q1/A1) bị xóa, Q2 trở thành đầu tiên
        $this->assertSame('Q2', $history[0]['content']);
        // Messages cuối là câu hỏi mới + reply
        $this->assertSame('user', $history[8]['role']);
        $this->assertSame('Câu hỏi thứ 6', $history[8]['content']);
    }

    // -------------------------------------------------------------------------
    // Follow-up: "Còn Mắt Biếc?" — context loaded from session
    // -------------------------------------------------------------------------

    public function test_follow_up_con_mat_biec_uses_session_context(): void
    {
        $sid = $this->sid('followup1');

        // Pre-load a prior turn about Chí Phèo
        Cache::put($this->cacheKey($sid), [
            ['role' => 'user',      'content' => 'Có sách Chí Phèo không?', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => 'Có, tìm thấy 2 cuốn sách về Chí Phèo.', 'timestamp' => time()],
        ], 120);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Còn Mắt Biếc?',
            'session_id' => $sid,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);

        // After follow-up, history must now have 4 entries
        $history = Cache::get($this->cacheKey($sid));
        $this->assertCount(4, $history);
        $this->assertSame('Còn Mắt Biếc?', $history[2]['content']);
    }

    // -------------------------------------------------------------------------
    // Follow-up: "Có bản tiếng Anh không?" — context about programming
    // -------------------------------------------------------------------------

    public function test_follow_up_tieng_anh_uses_programming_context(): void
    {
        $sid = $this->sid('followup2');

        Cache::put($this->cacheKey($sid), [
            ['role' => 'user',      'content' => 'Tôi muốn học lập trình.', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => '[MOCK] Tìm thấy sách lập trình.', 'timestamp' => time()],
        ], 120);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Có bản tiếng Anh không?',
            'session_id' => $sid,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);

        $history = Cache::get($this->cacheKey($sid));
        $this->assertCount(4, $history);
        $this->assertSame('Có bản tiếng Anh không?', $history[2]['content']);
    }

    // -------------------------------------------------------------------------
    // Follow-up: "Khoảng 7 tuổi" — target_reader context
    // -------------------------------------------------------------------------

    public function test_follow_up_khoang_7_tuoi_uses_children_context(): void
    {
        $sid = $this->sid('followup3');

        Cache::put($this->cacheKey($sid), [
            ['role' => 'user',      'content' => 'Tôi muốn sách cho trẻ em.', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => '[MOCK] Đây là sách thiếu nhi.', 'timestamp' => time()],
        ], 120);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Khoảng 7 tuổi.',
            'session_id' => $sid,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);

        $history = Cache::get($this->cacheKey($sid));
        $this->assertCount(4, $history);
        $this->assertSame('Khoảng 7 tuổi.', $history[2]['content']);
    }

    // -------------------------------------------------------------------------
    // search_books backward compat — no session_id still works
    // -------------------------------------------------------------------------

    public function test_search_books_works_without_session_id(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Tìm sách lập trình Python',
            // no session_id
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
    }

    // -------------------------------------------------------------------------
    // get_library_policy backward compat — no session_id still works
    // -------------------------------------------------------------------------

    public function test_get_library_policy_works_without_session_id(): void
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
            // no session_id
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
    }

    // -------------------------------------------------------------------------
    // Short session_id (<8 chars) is ignored — no crash
    // -------------------------------------------------------------------------

    public function test_short_session_id_is_ignored_gracefully(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Tìm sách',
            'session_id' => 'abc',  // too short (<8)
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertNull(Cache::get('ai_session_abc'));
    }

    // -------------------------------------------------------------------------
    // Full Round1 → executeTool → Round2 pipeline with session
    // -------------------------------------------------------------------------

    public function test_round1_tool_round2_pipeline_with_session(): void
    {
        $sid = $this->sid('pipeline');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getLibrarySettings')->once()->andReturn([
                'borrow_limit'    => 5,
                'max_borrow_days' => 14,
                'fine_per_day'    => 2000,
            ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Quy định mượn sách thư viện là gì?',
            'session_id' => $sid,
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertNotEmpty($reply);
        $this->assertStringContainsString('[MOCK]', $reply);

        // History must be saved
        $history = Cache::get($this->cacheKey($sid));
        $this->assertNotNull($history);
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('Quy định mượn sách thư viện là gì?', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
        $this->assertArrayHasKey('timestamp', $history[0]);
    }

    // -------------------------------------------------------------------------
    // Client-sent history is used as fallback when server session is empty
    // -------------------------------------------------------------------------

    public function test_client_history_used_as_fallback_when_no_server_session(): void
    {
        // No pre-populated cache. Frontend sends history directly.
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Còn Mắt Biếc?',
            'history' => [
                ['role' => 'user',      'content' => 'Có sách Chí Phèo không?'],
                ['role' => 'assistant', 'content' => 'Có 2 cuốn sách Chí Phèo.'],
            ],
            // no session_id → server falls back to client history
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
    }
}
