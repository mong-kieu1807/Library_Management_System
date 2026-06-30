<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BookService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * M2-AI.4 Context Preservation — cross-turn book context tests.
 *
 * Verifies that when a user says "đặt trước cuốn đó" in Turn 2, the system uses
 * the book_id saved from Turn 1 (check_book_availability / get_book_detail result)
 * rather than re-searching with a garbage query.
 */
class AIChatContextTest extends TestCase
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
            'email'     => 'ctx-test@example.com',
        ]);
        return $user;
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

    private function fakeAvailability(int $bookId, string $title, int $available = 0): array
    {
        return [
            'book_id'          => $bookId,
            'title'            => $title,
            'total_copies'     => 2,
            'available_copies' => $available,
            'borrowed_copies'  => 2 - $available,
            'reserved_copies'  => 0,
            'is_available'     => $available > 0,
        ];
    }

    private function fakeDetail(int $bookId, string $title): array
    {
        return [
            'book_id'          => $bookId,
            'title'            => $title,
            'description'      => 'Mô tả sách.',
            'authors'          => ['Nguyễn Nhật Ánh'],
            'categories'       => ['Văn học'],
            'publisher'        => 'NXB Trẻ',
            'language'         => 'vi',
            'avg_rating'       => 4.5,
            'total_reviews'    => 120,
            'available_copies' => 0,
        ];
    }

    private function fakeReserveSuccess(string $title, int $queuePos = 1, int $bookId = 42): array
    {
        return [
            'success'        => true,
            'reservation_id' => 10,
            'book_id'        => $bookId,
            'title'          => $title,
            'queue_position' => $queuePos,
            'status'         => 'waiting',
            'message'        => "Đặt trước sách \"{$title}\" thành công! Bạn đang ở vị trí {$queuePos} trong hàng chờ.",
        ];
    }

    // -------------------------------------------------------------------------
    // 1. Turn 1 availability check saves book_id + title into session history
    // -------------------------------------------------------------------------

    public function test_turn1_availability_check_saves_book_context_in_history(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-1-' . uniqid();

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(42, 'Mắt Biếc'));
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(42)
                 ->andReturn($this->fakeAvailability(42, 'Mắt Biếc', 0));
        });

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Sách Mắt Biếc còn không?',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history = Cache::get('ai_session_' . $sid, []);
        $this->assertNotEmpty($history);

        $assistantEntry = collect($history)->firstWhere('role', 'assistant');
        $this->assertNotNull($assistantEntry);
        $this->assertSame(42, $assistantEntry['last_book_id'] ?? null,
            'saveHistory() phải lưu last_book_id=42 từ check_book_availability response');
        $this->assertSame('Mắt Biếc', $assistantEntry['last_book_title'] ?? null);
    }

    // -------------------------------------------------------------------------
    // 2. Full two-turn flow: availability check → "Đặt trước cuốn đó" = correct book,
    //    searchBooks called exactly once (Turn 1 only), createReservation once (Turn 2)
    // -------------------------------------------------------------------------

    public function test_full_two_turn_contextual_reserve_uses_correct_book_id(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-2-' . uniqid();

        // ONE mock for the entire test — Turn 1 uses searchBooks+getBookAvailability,
        // Turn 2 uses createReservation only (no extra searchBooks call expected).
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(42, 'Mắt Biếc'));
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(42)
                 ->andReturn($this->fakeAvailability(42, 'Mắt Biếc', 0));
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 42)
                 ->andReturn($this->fakeReserveSuccess('Mắt Biếc', 1, 42));
        });

        // Turn 1 — availability check
        $resp1 = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Sách Mắt Biếc còn không?',
            'session_id' => $sid,
        ]);
        $resp1->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $resp1->json('reply'));

        // Turn 2 — contextual reserve: must use book_id=42 directly, NOT call searchBooks again
        $resp2 = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước cuốn đó cho tôi',
            'session_id' => $sid,
        ]);

        $resp2->assertStatus(200);
        $reply = $resp2->json('reply');
        $this->assertStringContainsString('[MOCK]', $reply);
        $this->assertStringContainsString('Mắt Biếc', $reply,
            'Reply phải nhắc tên Mắt Biếc — không được reserve sách khác');
    }

    // -------------------------------------------------------------------------
    // 3. The original bug: "Đặt trước cuốn đó" must reserve book_id from context,
    //    not a random book returned by a bad search query
    // -------------------------------------------------------------------------

    public function test_contextual_reserve_captures_correct_book_id_not_wrong_book(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-3-' . uniqid();

        $reservedBookId = null;

        $this->mock(BookService::class, function ($mock) use (&$reservedBookId) {
            // Turn 1
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(42, 'Mắt Biếc'));
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(42)
                 ->andReturn($this->fakeAvailability(42, 'Mắt Biếc', 0));
            // Turn 2 — capture which book_id was actually passed
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->andReturnUsing(function (int $uid, int $bid) use (&$reservedBookId) {
                     $reservedBookId = $bid;
                     return [
                         'success'        => true,
                         'reservation_id' => 10,
                         'book_id'        => $bid,
                         'title'          => 'Mắt Biếc',
                         'queue_position' => 1,
                         'status'         => 'waiting',
                         'message'        => 'OK',
                     ];
                 });
        });

        // Turn 1
        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Mắt Biếc còn không?',
            'session_id' => $sid,
        ])->assertStatus(200);

        // Turn 2
        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước cuốn đó cho tôi',
            'session_id' => $sid,
        ])->assertStatus(200);

        $this->assertSame(42, $reservedBookId,
            'createReservation phải nhận book_id=42 (Mắt Biếc), không phải sách ngẫu nhiên — đây là bug gốc M2-AI.4');
    }

    // -------------------------------------------------------------------------
    // 4. Explicit title ("Đặt trước Chí Phèo") after context → triggers new search
    //    No contextual pronoun → mock routes to search_books (explicit title path)
    // -------------------------------------------------------------------------

    public function test_explicit_title_reservation_triggers_new_search_ignoring_context(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-4-' . uniqid();

        // Seed history with Mắt Biếc context
        Cache::put('ai_session_' . $sid, [
            ['role' => 'user',      'content' => 'Mắt Biếc còn không?',         'timestamp' => time()],
            ['role' => 'assistant', 'content' => 'Mắt Biếc hết rồi.',            'timestamp' => time(),
             'last_book_id' => 42, 'last_book_title' => 'Mắt Biếc'],
        ], 120);

        // "Đặt trước Chí Phèo" has no contextual pronoun → mock routes to search_books
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(88, 'Chí Phèo'));
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 88)
                 ->andReturn($this->fakeReserveSuccess('Chí Phèo', 1, 88));
        });

        $resp = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước Chí Phèo',
            'session_id' => $sid,
        ]);

        $resp->assertStatus(200);
        $this->assertStringContainsString('Chí Phèo', $resp->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 5. "Sách đó" pronoun variant → resolve_context_book → reserve_book(55)
    // -------------------------------------------------------------------------

    public function test_sach_do_variant_also_uses_book_context(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-5-' . uniqid();

        Cache::put('ai_session_' . $sid, [
            ['role' => 'user',      'content' => 'Sách Tôi Thấy Hoa Vàng còn không?', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => 'Sách hết rồi.',                      'timestamp' => time(),
             'last_book_id' => 55, 'last_book_title' => 'Tôi Thấy Hoa Vàng Trên Cỏ Xanh'],
        ], 120);

        // "Đặt trước sách đó" → isContextualPronoun=true + isReservationQuestion=true → resolve_context_book → reserve_book(55)
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldNotReceive('searchBooks');
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 55)
                 ->andReturn($this->fakeReserveSuccess('Tôi Thấy Hoa Vàng Trên Cỏ Xanh', 2, 55));
        });

        $resp = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước sách đó cho tôi',
            'session_id' => $sid,
        ]);

        $resp->assertStatus(200);
        $this->assertStringContainsString('[MOCK]', $resp->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 6. Multiple history turns: most recent book_id wins
    // -------------------------------------------------------------------------

    public function test_multiple_history_turns_most_recent_book_id_wins(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-6-' . uniqid();

        // Two entries with different book_ids — most recent (88) must win
        Cache::put('ai_session_' . $sid, [
            ['role' => 'user',      'content' => 'Mắt Biếc còn không?', 'timestamp' => time() - 60],
            ['role' => 'assistant', 'content' => 'Mắt Biếc hết.',        'timestamp' => time() - 60,
             'last_book_id' => 42, 'last_book_title' => 'Mắt Biếc'],
            ['role' => 'user',      'content' => 'Chí Phèo còn không?', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => 'Chí Phèo hết.',        'timestamp' => time(),
             'last_book_id' => 88, 'last_book_title' => 'Chí Phèo'],
        ], 120);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldNotReceive('searchBooks');
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 88)
                 ->andReturn($this->fakeReserveSuccess('Chí Phèo', 1, 88));
        });

        $resp = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước cuốn đó đi',
            'session_id' => $sid,
        ]);

        $resp->assertStatus(200);
        $this->assertStringContainsString('Chí Phèo', $resp->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 7. History without last_book_id + contextual pronoun → resolve_context_book
    //    returns found=false → text reply asking which book (no search, no reserve)
    // -------------------------------------------------------------------------

    public function test_old_history_without_book_id_returns_clarification_text(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-7-' . uniqid();

        // History without last_book_id — toolResolveContextBook returns found=false
        Cache::put('ai_session_' . $sid, [
            ['role' => 'user',      'content' => 'Atomic Habits còn không?', 'timestamp' => time()],
            ['role' => 'assistant', 'content' => 'Atomic Habits hết rồi.',  'timestamp' => time()],
        ], 120);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldNotReceive('searchBooks');
            $mock->shouldNotReceive('createReservation');
        });

        $resp = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước cuốn đó cho tôi',
            'session_id' => $sid,
        ]);

        $resp->assertStatus(200)->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $resp->json('reply'));
    }

    // -------------------------------------------------------------------------
    // 8. Turn 1 book detail check → Turn 2 "Đặt trước cuốn sách đó" uses correct book_id
    // -------------------------------------------------------------------------

    public function test_contextual_reserve_after_book_detail_uses_correct_book_id(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-8-' . uniqid();

        // ONE mock for both turns
        $this->mock(BookService::class, function ($mock) {
            // Turn 1: giới thiệu → search + get_book_detail
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(77, 'Lão Hạc'));
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(77)
                 ->andReturn($this->fakeDetail(77, 'Lão Hạc'));
            // Turn 2: direct reserve (no search)
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 77)
                 ->andReturn($this->fakeReserveSuccess('Lão Hạc', 1, 77));
        });

        // Turn 1
        $resp1 = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Giới thiệu sách Lão Hạc cho tôi',
            'session_id' => $sid,
        ]);
        $resp1->assertStatus(200);
        $this->assertStringContainsString('Lão Hạc', $resp1->json('reply'));

        // History must have last_book_id=77 from get_book_detail result
        $history      = Cache::get('ai_session_' . $sid, []);
        $assistant    = collect($history)->firstWhere('role', 'assistant');
        $this->assertSame(77, $assistant['last_book_id'] ?? null,
            'get_book_detail response phải lưu last_book_id vào history');

        // Turn 2 — "cuốn sách đó" → isContextualPronoun=true + isReservationQuestion=true → resolve_context_book → reserve_book(77)
        $resp2 = $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước cuốn sách đó cho tôi',
            'session_id' => $sid,
        ]);

        $resp2->assertStatus(200);
        $this->assertStringContainsString('Lão Hạc', $resp2->json('reply'));
    }

    // =========================================================================
    // Regression — extractBookContextFromContents priority rules
    // =========================================================================

    // R1. search_books: single result → lưu last_book_id
    public function test_regression_search_single_result_saves_book_context(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-r1-' . uniqid();

        // searchBooks returns exactly 1 book → toolSearchBooks wraps as {count:1, found:true}
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn($this->fakeSearch(5, 'Python Cơ Bản'));
        });

        // "Tìm sách Python" → mock: search → text (no intro/availability/reserve chaining)
        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Tìm sách Python',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history   = Cache::get('ai_session_' . $sid, []);
        $assistant = collect($history)->firstWhere('role', 'assistant');
        $this->assertSame(5, $assistant['last_book_id'] ?? null,
            'Single search result → extractBookContextFromContents phải lưu last_book_id=5');
        $this->assertSame('Python Cơ Bản', $assistant['last_book_title'] ?? null);
    }

    // R2. search_books: nhiều kết quả → KHÔNG lưu last_book_id (context ambiguous)
    public function test_regression_search_multiple_results_does_not_save_book_context(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-r2-' . uniqid();

        // searchBooks returns 2 books → count=2 → must NOT be saved as context
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('searchBooks')
                 ->once()
                 ->andReturn(array_merge(
                     $this->fakeSearch(5, 'Python Cơ Bản'),
                     $this->fakeSearch(6, 'Django Web Framework')
                 ));
        });

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Tìm sách lập trình',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history   = Cache::get('ai_session_' . $sid, []);
        $assistant = collect($history)->firstWhere('role', 'assistant');
        $this->assertNull($assistant['last_book_id'] ?? null,
            'Multi-result search → extractBookContextFromContents phải trả [] (ambiguous), không lưu last_book_id');
        $this->assertArrayNotHasKey('last_book_id', $assistant ?? [],
            'Key last_book_id không được tồn tại trong history entry khi search trả nhiều kết quả');
    }

    // R3. get_book_detail functionResponse → luôn lưu last_book_id
    public function test_regression_get_book_detail_saves_book_context(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-r3-' . uniqid();

        // "Giới thiệu sách ID 77" → mock: isIntroQuestion=true + ID regex → get_book_detail(77) trực tiếp
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookDetail')
                 ->once()
                 ->with(77)
                 ->andReturn($this->fakeDetail(77, 'Lão Hạc'));
        });

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Giới thiệu sách ID 77',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history   = Cache::get('ai_session_' . $sid, []);
        $assistant = collect($history)->firstWhere('role', 'assistant');
        $this->assertSame(77, $assistant['last_book_id'] ?? null,
            'get_book_detail functionResponse phải luôn lưu last_book_id');
        $this->assertSame('Lão Hạc', $assistant['last_book_title'] ?? null);
    }

    // R4. check_book_availability functionResponse → luôn lưu last_book_id
    public function test_regression_check_book_availability_saves_book_context(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-r4-' . uniqid();

        // "Sách ID 5 còn không?" → mock: isAvailabilityQuestion=true + ID regex → check_book_availability(5)
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getBookAvailability')
                 ->once()
                 ->with(5)
                 ->andReturn($this->fakeAvailability(5, 'Mắt Biếc', 0));
        });

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Sách ID 5 còn không?',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history   = Cache::get('ai_session_' . $sid, []);
        $assistant = collect($history)->firstWhere('role', 'assistant');
        $this->assertSame(5, $assistant['last_book_id'] ?? null,
            'check_book_availability functionResponse phải luôn lưu last_book_id');
        $this->assertSame('Mắt Biếc', $assistant['last_book_title'] ?? null);
    }

    // R5. reserve_book functionResponse → luôn lưu last_book_id
    public function test_regression_reserve_book_saves_book_context(): void
    {
        $this->actingAs($this->authenticatedUser(), 'sanctum');
        $sid = 'ctx-r5-' . uniqid();

        // "Đặt trước sách ID 42" → mock: isReservationQuestion=true + ID regex → reserve_book(42)
        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('createReservation')
                 ->once()
                 ->with(99, 42)
                 ->andReturn($this->fakeReserveSuccess('Mắt Biếc', 1, 42));
        });

        $this->postJson('/api/v1/ai/chat', [
            'message'    => 'Đặt trước sách ID 42',
            'session_id' => $sid,
        ])->assertStatus(200);

        $history   = Cache::get('ai_session_' . $sid, []);
        $assistant = collect($history)->firstWhere('role', 'assistant');
        $this->assertSame(42, $assistant['last_book_id'] ?? null,
            'reserve_book functionResponse phải luôn lưu last_book_id');
        $this->assertSame('Mắt Biếc', $assistant['last_book_title'] ?? null);
    }
}
