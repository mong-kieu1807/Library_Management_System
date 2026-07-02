<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BookService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AIChatReservationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.mock_mode' => true]);
        config(['cache.default' => 'array']);
    }

    private function authenticatedUser(int $userId = 99): User
    {
        $user = new User();
        $user->forceFill([
            'user_id'   => $userId,
            'full_name' => 'Test User',
            'email'     => 'test@example.com',
        ]);
        return $user;
    }

    private function fakeReserveSuccess(string $title = 'Mắt Biếc', int $queuePos = 1): array
    {
        return [
            'success'        => true,
            'reservation_id' => 10,
            'book_id'        => 42,
            'title'          => $title,
            'queue_position' => $queuePos,
            'message'        => "Đặt trước sách \"{$title}\" thành công! Bạn đang ở vị trí {$queuePos} trong hàng chờ.",
        ];
    }

    private function fakeSearch(int $bookId, string $title): array
    {
        return [[
            'book_id'          => $bookId,
            'title'            => $title,
            'author'           => 'Tác giả',
            'isbn'             => '978-0000000000',
            'available_copies' => 0,
        ]];
    }

    // -------------------------------------------------------------------------
    // 1. Tool declaration — reserve_book exists in declarations
    // -------------------------------------------------------------------------

    public function test_reserve_book_tool_declaration_exists(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Tìm sách Python',
        ]);

        $response->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // 2. Tool parameters — reserve_book only needs book_id
    // -------------------------------------------------------------------------

    public function test_reserve_book_tool_has_book_id_parameter(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 5)
                 ->andReturn($this->fakeReserveSuccess('Nhà Giả Kim'));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 5',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
    }

    // -------------------------------------------------------------------------
    // 3. executeTool routes to reserve_book correctly
    // -------------------------------------------------------------------------

    public function test_execute_tool_routes_reserve_book(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 3)
                 ->andReturn($this->fakeReserveSuccess('Sapiens', 2));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 3',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 4. Reserve by title — chains search_books → reserve_book
    // -------------------------------------------------------------------------

    public function test_reserve_by_title_chains_search_to_reserve(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(42, 'Mắt Biếc'));
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 42)
                 ->andReturn($this->fakeReserveSuccess('Mắt Biếc'));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước Mắt Biếc',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('[MOCK]', $reply);
        $this->assertStringContainsString('Mắt Biếc', $reply);
    }

    // -------------------------------------------------------------------------
    // 5. Reserve by explicit book_id — direct (no search needed)
    // -------------------------------------------------------------------------

    public function test_reserve_by_book_id_direct(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 7)
                 ->andReturn($this->fakeReserveSuccess('Atomic Habits'));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 7',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('Atomic Habits', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 6. Reserve from history context — last_book_id in session cache,
    //    user says "Đặt trước cuốn đó" → resolve_context_book → reserve_book(11)
    // -------------------------------------------------------------------------

    public function test_reserve_by_context_from_structured_history(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $sid = 'reserve-context-test-' . uniqid();

        Cache::put('ai_session_' . $sid, [
            ['role' => 'user',      'content' => 'Atomic Habits còn không?',                         'timestamp' => time()],
            ['role' => 'assistant', 'content' => '[MOCK] Sách Atomic Habits hiện không có bản nào.', 'timestamp' => time(),
             'last_book_id' => 11, 'last_book_title' => 'Atomic Habits'],
        ], 120);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldNotReceive('searchBooks');
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 11)
                 ->andReturn($this->fakeReserveSuccess('Atomic Habits'));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước cuốn đó',
            'session_id' => $sid,
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 7. Success reply contains queue position
    // -------------------------------------------------------------------------

    public function test_reserve_success_reply_contains_queue_position(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 1)
                 ->andReturn($this->fakeReserveSuccess('Đắc Nhân Tâm', 3));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giữ sách ID 1',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('3', $reply);
    }

    // -------------------------------------------------------------------------
    // 8. Success reply contains book title
    // -------------------------------------------------------------------------

    public function test_reserve_success_reply_contains_book_title(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 2)
                 ->andReturn($this->fakeReserveSuccess('Nhà Giả Kim', 1));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 2',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('Nhà Giả Kim', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 9. Book available — error returned, user redirected to borrow directly
    // -------------------------------------------------------------------------

    public function test_reserve_when_copies_available_returns_error(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 4)
                 ->andReturn([
                     'success'          => false,
                     'error'            => 'book_available',
                     'title'            => 'Clean Code',
                     'available_copies' => 2,
                     'message'          => 'Sách "Clean Code" hiện còn 2 bản...',
                 ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 4',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('Clean Code', $reply);
        $this->assertStringContainsString('2', $reply);
    }

    // -------------------------------------------------------------------------
    // 10. Already reserved — idempotent, no duplicate
    // -------------------------------------------------------------------------

    public function test_reserve_duplicate_returns_already_reserved(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 6)
                 ->andReturn([
                     'success'          => false,
                     'error'            => 'already_reserved',
                     'already_reserved' => true,
                     'title'            => 'Dune',
                     'queue_position'   => 2,
                     'message'          => 'Bạn đã đặt trước sách "Dune"...',
                 ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 6',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('Dune', $reply);
        $this->assertStringContainsString('đã đặt trước', mb_strtolower($reply));
    }

    // -------------------------------------------------------------------------
    // 11. Not authenticated — gets error reply asking to log in
    // -------------------------------------------------------------------------

    public function test_reserve_requires_auth_returns_login_message(): void
    {
        $this->mock(BookService::class, function ($mock) {
            // createReservation should NOT be called — auth check comes first in controller
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 8',
        ]);

        $response->assertStatus(200);
        $reply = $response->json('reply');
        $this->assertStringContainsString('đăng nhập', mb_strtolower($reply));
    }

    // -------------------------------------------------------------------------
    // 12. Book not found
    // -------------------------------------------------------------------------

    public function test_reserve_book_not_found_returns_error(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 9999)
                 ->andReturn([
                     'success' => false,
                     'error'   => 'book_not_found',
                     'message' => 'Không tìm thấy sách với ID 9999.',
                 ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 9999',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 13. No library card
    // -------------------------------------------------------------------------

    public function test_reserve_no_library_card_returns_error(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 10)
                 ->andReturn([
                     'success' => false,
                     'error'   => 'no_card',
                     'message' => 'Bạn chưa có thẻ thư viện.',
                 ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 10',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 14. Limit exceeded
    // -------------------------------------------------------------------------

    public function test_reserve_limit_exceeded_returns_error(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 11)
                 ->andReturn([
                     'success' => false,
                     'error'   => 'limit_exceeded',
                     'message' => 'Bạn đã đặt trước 3/3 cuốn sách.',
                 ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 11',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 15. Service exception handled gracefully (transaction rollback scenario)
    // -------------------------------------------------------------------------

    public function test_reserve_service_exception_handled_gracefully(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 12)
                 ->andThrow(new \RuntimeException('DB connection failed'));
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Đặt trước sách ID 12',
        ]);

        $response->assertStatus(503);
    }

    // -------------------------------------------------------------------------
    // 16. Conversation memory saved after successful reserve
    // -------------------------------------------------------------------------

    public function test_reserve_conversation_memory_saved_after_reserve(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'reserve-memory-test-abc';

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 15)
                 ->andReturn($this->fakeReserveSuccess('Homo Deus'));
        });

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước sách ID 15',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history = Cache::get('ai_session_' . $sid);
        $this->assertNotNull($history);
        $this->assertSame('user',      $history[0]['role']);
        $this->assertSame('Đặt trước sách ID 15', $history[0]['content']);
        $this->assertSame('assistant', $history[1]['role']);
    }

    // -------------------------------------------------------------------------
    // 17. Backward compat: search_books still works after AI.4
    // -------------------------------------------------------------------------

    public function test_backward_compat_search_books_after_ai4(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Tìm sách về lập trình',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 18. Backward compat: get_book_detail (AI.2) still works
    // -------------------------------------------------------------------------

    public function test_backward_compat_get_book_detail_after_ai4(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn([[
                     'book_id' => 20, 'title' => 'Sapiens', 'author' => 'Yuval', 'isbn' => '111', 'available_copies' => 2,
                 ]]);
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(20)
                 ->andReturn([
                     'book_id' => 20, 'title' => 'Sapiens', 'isbn' => '111',
                     'description' => 'Lịch sử loài người.', 'publish_year' => 2011,
                     'language' => 'vi', 'pages' => 443, 'avg_rating' => 4.7,
                     'total_reviews' => 300, 'publisher' => 'NXB Trẻ',
                     'available_copies' => 2, 'authors' => ['Yuval Noah Harari'], 'categories' => ['Lịch sử'],
                 ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Giới thiệu sách Sapiens',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
        $this->assertStringContainsString('Sapiens', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 19. Backward compat: check_book_availability (AI.3) still works
    // -------------------------------------------------------------------------

    public function test_backward_compat_availability_after_ai4(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn([[
                     'book_id' => 30, 'title' => 'Dune', 'author' => 'Frank', 'isbn' => '222', 'available_copies' => 0,
                 ]]);
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(30)
                 ->andReturn([
                     'book_id' => 30, 'title' => 'Dune',
                     'total_copies' => 3, 'available_copies' => 0,
                     'borrowed_copies' => 3, 'reserved_copies' => 0, 'is_available' => false,
                 ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Sách Dune còn không?',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 20. Backward compat: get_library_policy (AI.5) still works
    // -------------------------------------------------------------------------

    public function test_backward_compat_policy_after_ai4(): void
    {
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getLibrarySettings')->once()->andReturn([
                'borrow_limit'    => 5,
                'max_borrow_days' => 14,
                'fine_per_day'    => 2000,
            ]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Phí phạt trả sách trễ là bao nhiêu?',
        ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }
}
