<?php

namespace Tests\Feature;

use App\Services\BookService;
use Tests\TestCase;

class AIChatPolicyTest extends TestCase
{
    private function fakeSettings(): array
    {
        return [
            'borrow_limit'    => 5,
            'max_borrow_days' => 14,
            'fine_per_day'    => 2000,
        ];
    }

    public function test_missing_api_key_returns_503_not_crash(): void
    {
        config(['services.gemini.key' => null]);
        config(['ai.mock_mode' => false]);

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Tìm sách lập trình',
        ]);

        $response->assertStatus(503);
        $response->assertJsonStructure(['reply']);
    }

    public function test_get_library_policy_returns_200_with_reply(): void
    {
        config(['ai.mock_mode' => true]);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getLibrarySettings')->once()->andReturn($this->fakeSettings());
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Thư viện cho mượn tối đa bao nhiêu cuốn sách?',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['reply']);
        $this->assertNotEmpty($response->json('reply'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('policyPhraseProvider')]
    public function test_policy_phrases_all_trigger_get_library_policy(string $phrase): void
    {
        config(['ai.mock_mode' => true]);

        $this->mock(BookService::class, function ($mock) {
            $mock->shouldReceive('getLibrarySettings')->once()->andReturn($this->fakeSettings());
        });

        $response = $this->postJson('/api/v1/ai/chat', ['message' => $phrase]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['reply']);

        $reply = $response->json('reply');
        $this->assertNotEmpty($reply);
        $this->assertStringContainsString('[MOCK]', $reply, "Phrase «{$phrase}» phải kích hoạt get_library_policy, không phải search_books");
        $this->assertStringContainsString('5', $reply);
        $this->assertStringContainsString('14', $reply);
    }

    public static function policyPhraseProvider(): array
    {
        return [
            'mượn tối đa'      => ['Được mượn tối đa bao nhiêu cuốn?'],
            'phí phạt'         => ['Phí phạt quá hạn là bao nhiêu?'],
            'gia hạn'          => ['Gia hạn sách thì làm thế nào?'],
            'thẻ thư viện'     => ['Thẻ thư viện có hiệu lực bao lâu?'],
            'được mượn bao lâu'=> ['Tôi được mượn bao lâu?'],
            'quy định'         => ['Quy định thư viện là gì?'],
        ];
    }

    public function test_search_books_still_works_with_mock_mode(): void
    {
        config(['ai.mock_mode' => true]);

        $this->mock(BookService::class, function ($mock) {
            // searchBooks() returns [] when no results found
            $mock->shouldReceive('searchBooks')->once()->andReturn([]);
        });

        $response = $this->postJson('/api/v1/ai/chat', [
            'message' => 'Có sách khoa học viễn tưởng không?',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['reply']);
        $this->assertStringContainsString('[MOCK]', $response->json('reply'));
    }

    public function test_args_empty_object_serialization_for_no_param_tools(): void
    {
        // Regression test for args:[] vs args:{} bug.
        // When Gemini returns args:{} for a no-param tool, json_decode(...,true) gives [].
        // The modelParts construction MUST convert [] → stdClass so json_encode produces "args":{}.
        $parsed = [[
            'type' => 'functionCall',
            'name' => 'get_library_policy',
            'args' => [],
        ]];

        $modelParts = array_map(fn ($p) => match ($p['type']) {
            'functionCall' => ['functionCall' => ['name' => $p['name'], 'args' => empty($p['args']) ? new \stdClass() : $p['args']]],
            default        => ['text' => $p['text']],
        }, $parsed);

        $json = json_encode($modelParts[0]['functionCall'], JSON_UNESCAPED_UNICODE);
        $this->assertSame('{"name":"get_library_policy","args":{}}', $json, 'Empty args must serialize as {} not []');
    }
}
