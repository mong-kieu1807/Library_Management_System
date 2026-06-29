<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AIAnalysisService
{
    private string $apiKey;
    private string $endpoint;

    public function __construct()
    {
        $this->apiKey   = config('services.gemini.key') ?? '';
        $model          = config('ai.model', 'gemini-2.5-flash');
        $this->endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
    }

    /**
     * Send a multi-turn conversation to Gemini and return the raw response array.
     * When AI_MOCK_MODE=true, returns a deterministic fake response without calling Gemini.
     *
     * @param  array  $contents  Gemini contents array (role+parts)
     * @param  array  $tools     Function declarations (empty = no tools)
     * @param  string $systemPrompt  Optional system instruction text
     */
    public function generate(array $contents, array $tools = [], string $systemPrompt = ''): array
    {
        if (config('ai.mock_mode', false)) {
            return $this->mockGenerate($contents, $tools);
        }

        if ($this->apiKey === '') {
            throw new \RuntimeException('Gemini API key is missing.');
        }

        $payload = ['contents' => $contents];

        if ($systemPrompt !== '') {
            $payload['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
        }

        if (!empty($tools)) {
            $payload['tools'] = [['function_declarations' => $tools]];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $response = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
            ->withBody($body, 'application/json')
            ->post($this->endpoint . '?key=' . $this->apiKey);

        if ($response->status() === 429) {
            throw new \RuntimeException('RATE_LIMIT_EXCEEDED', 429);
        }

        if ($response->failed()) {
            throw new \RuntimeException('Gemini API error: ' . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Return a deterministic mock response that exercises the full Function-Calling flow.
     *
     * Round 1 (user message):
     *   - Policy question → get_library_policy functionCall
     *   - Otherwise       → search_books functionCall
     * Round 2 (functionResponse): returns a text reply based on the tool result.
     */
    private function mockGenerate(array $contents, array $tools): array
    {
        $last     = end($contents);
        $lastPart = $last['parts'][0] ?? [];

        if (isset($lastPart['functionResponse'])) {
            // Round 2 — build reply from tool result
            $toolName = $lastPart['functionResponse']['name'] ?? '';
            $result   = $lastPart['functionResponse']['response']['result'] ?? [];

            if ($toolName === 'get_library_policy') {
                $limit = $result['borrow_limit']    ?? 5;
                $days  = $result['max_borrow_days'] ?? 14;
                $fine  = $result['fine_per_day']    ?? 2000;
                $text  = "[MOCK] Quy định thư viện: Mượn tối đa {$limit} cuốn, thời hạn {$days} ngày, "
                       . 'phí phạt ' . number_format((int) $fine, 0, ',', '.') . ' đồng/ngày.';
            } else {
                $found    = $result['found'] ?? false;
                $count    = (int) ($result['count'] ?? 0);
                $topic    = $result['topic'] ?? '';
                $searched = implode(', ', array_slice((array) ($result['searched'] ?? []), 0, 3));

                if ($found && $count > 0) {
                    $text = "[MOCK] Tìm thấy {$count} cuốn sách phù hợp. Đây là kết quả từ chế độ Mock — Gemini không được gọi.";
                } else {
                    $label = $topic ?: $searched ?: 'từ khóa đã cho';
                    $text  = "[MOCK] Thư viện hiện chưa có sách về \"{$label}\". Bạn có muốn thử từ khóa khác không?";
                }
            }

            return [
                'candidates' => [[
                    'content'      => ['parts' => [['text' => $text]]],
                    'finishReason' => 'STOP',
                ]],
            ];
        }

        // Round 1 — extract the last user text to decide which tool to call
        $userText = '';
        foreach (array_reverse($contents) as $c) {
            if (($c['role'] ?? '') === 'user' && isset($c['parts'][0]['text'])) {
                $userText = $c['parts'][0]['text'];
                break;
            }
        }

        if ($this->isPolicyQuestion($userText)) {
            return [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'get_library_policy',
                                'args' => [],
                            ],
                        ]],
                    ],
                    'finishReason' => 'STOP',
                ]],
            ];
        }

        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'functionCall' => [
                            'name' => 'search_books',
                            'args' => [
                                'query'    => $userText,
                                'keywords' => [],
                                'limit'    => 5,
                            ],
                        ],
                    ]],
                ],
                'finishReason' => 'STOP',
            ]],
        ];
    }

    private function isPolicyQuestion(string $text): bool
    {
        $lower    = mb_strtolower($text);
        $keywords = [
            'quy định', 'nội quy', 'quy tắc',
            'phí phạt', 'tiền phạt', 'trả trễ',
            'thời hạn mượn', 'mượn tối đa', 'mượn mấy cuốn', 'được mượn',
            'bao nhiêu cuốn', 'thẻ thư viện', 'thẻ đọc sách',
            'gia hạn', 'hạn trả',
        ];
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parse Gemini response parts into typed items.
     *
     * Returns array of:
     *   ['type'=>'text', 'text'=>string]
     *   ['type'=>'functionCall', 'name'=>string, 'args'=>array]
     */
    public function parseParts(array $parts): array
    {
        $parsed = [];
        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $parsed[] = [
                    'type' => 'functionCall',
                    'name' => $part['functionCall']['name'],
                    'args' => $part['functionCall']['args'] ?? [],
                ];
            } elseif (isset($part['text'])) {
                $parsed[] = [
                    'type' => 'text',
                    'text' => $part['text'],
                ];
            }
        }
        return $parsed;
    }
}
