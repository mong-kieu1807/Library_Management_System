<?php

namespace Tests\Unit;

use App\Services\AIAnalysisService;
use Tests\TestCase;

class AIAnalysisServiceTest extends TestCase
{
    public function test_constructor_does_not_crash_when_api_key_is_null(): void
    {
        config(['services.gemini.key' => null]);
        config(['ai.mock_mode' => false]);

        $service = new AIAnalysisService();
        $this->assertInstanceOf(AIAnalysisService::class, $service);
    }

    public function test_generate_throws_runtime_exception_when_api_key_missing(): void
    {
        config(['services.gemini.key' => null]);
        config(['ai.mock_mode' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini API key is missing.');

        $service  = new AIAnalysisService();
        $contents = [['role' => 'user', 'parts' => [['text' => 'test']]]];
        $service->generate($contents);
    }

    public function test_generate_does_not_throw_when_api_key_present(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        config(['ai.mock_mode' => true]);

        $service  = new AIAnalysisService();
        $contents = [['role' => 'user', 'parts' => [['text' => 'tìm sách lập trình']]]];
        $result   = $service->generate($contents);

        $this->assertArrayHasKey('candidates', $result);
    }

    public function test_mock_returns_get_library_policy_for_policy_question(): void
    {
        config(['ai.mock_mode' => true]);

        $service  = new AIAnalysisService();
        $contents = [['role' => 'user', 'parts' => [['text' => 'Thư viện cho mượn tối đa bao nhiêu cuốn?']]]];
        $result   = $service->generate($contents);

        $part = $result['candidates'][0]['content']['parts'][0] ?? [];
        $this->assertArrayHasKey('functionCall', $part);
        $this->assertSame('get_library_policy', $part['functionCall']['name']);
    }

    public function test_mock_returns_search_books_for_non_policy_question(): void
    {
        config(['ai.mock_mode' => true]);

        $service  = new AIAnalysisService();
        $contents = [['role' => 'user', 'parts' => [['text' => 'Có sách Chí Phèo không?']]]];
        $result   = $service->generate($contents);

        $part = $result['candidates'][0]['content']['parts'][0] ?? [];
        $this->assertArrayHasKey('functionCall', $part);
        $this->assertSame('search_books', $part['functionCall']['name']);
    }

    public function test_parseParts_returns_empty_array_for_no_param_tool(): void
    {
        // Simulates what happens after json_decode('{}', true) = []
        // parseParts() stores args as [] — that's correct for PHP use.
        // The serialization fix ([] → stdClass) must happen in modelParts construction.
        config(['ai.mock_mode' => true]);
        $service = new AIAnalysisService();

        $parts = [[
            'functionCall' => [
                'name' => 'get_library_policy',
                'args' => [],   // {} decoded with assoc=true
            ],
        ]];

        $parsed = $service->parseParts($parts);
        $this->assertSame('get_library_policy', $parsed[0]['name']);
        $this->assertSame([], $parsed[0]['args']);   // stays [] in PHP
    }

    public function test_mock_round2_generates_text_for_policy_response(): void
    {
        config(['ai.mock_mode' => true]);

        $service  = new AIAnalysisService();
        $contents = [[
            'role'  => 'user',
            'parts' => [[
                'functionResponse' => [
                    'name'     => 'get_library_policy',
                    'response' => [
                        'result' => [
                            'borrow_limit'    => 3,
                            'max_borrow_days' => 21,
                            'fine_per_day'    => 5000,
                        ],
                    ],
                ],
            ]],
        ]];
        $result = $service->generate($contents);

        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $this->assertStringContainsString('[MOCK]', $text);
        $this->assertStringContainsString('3', $text);
        $this->assertStringContainsString('21', $text);
    }
}
