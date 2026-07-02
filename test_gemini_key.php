<?php
// Test Gemini HTTP call — full production payload (with tools + system_instruction)
require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$key      = config('services.gemini.key');
$model    = config('ai.model', 'gemini-2.5-flash');
$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
$systemPrompt = config('ai.system_prompt', '');

echo "KEY_PREFIX=" . substr($key, 0, 15) . "..." . PHP_EOL;
echo "ENDPOINT=" . $endpoint . PHP_EOL;
echo "MOCK_MODE=" . (config('ai.mock_mode') ? 'true' : 'false') . PHP_EOL;
echo "SYSTEM_PROMPT_LEN=" . strlen($systemPrompt) . PHP_EOL;
echo PHP_EOL;

$tools = [
    [
        'name'        => 'search_books',
        'description' => 'Tìm kiếm sách trong thư viện.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'query'    => ['type' => 'string'],
                'keywords' => ['type' => 'array', 'items' => ['type' => 'string']],
                'limit'    => ['type' => 'integer'],
            ],
            'required'   => [],
        ],
    ],
    [
        'name'        => 'reserve_book',
        'description' => 'Đặt trước sách.',
        'parameters'  => [
            'type'       => 'object',
            'properties' => [
                'book_id' => ['type' => 'integer'],
            ],
            'required'   => ['book_id'],
        ],
    ],
];

$payload = [
    'contents' => [['role' => 'user', 'parts' => [['text' => 'Có sách Mắt Biếc không?']]]],
    'tools'    => [['function_declarations' => $tools]],
];

if ($systemPrompt !== '') {
    $payload['system_instruction'] = ['parts' => [['text' => $systemPrompt]]];
}

$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$resp = \Illuminate\Support\Facades\Http::timeout(30)
    ->withHeaders(['Accept' => 'application/json'])
    ->withBody($body, 'application/json')
    ->post($endpoint . '?key=' . $key);

echo "HTTP_STATUS=" . $resp->status() . PHP_EOL;
echo "FAILED=" . ($resp->failed() ? 'true' : 'false') . PHP_EOL;
echo "FULL_BODY=" . $resp->body() . PHP_EOL;
echo PHP_EOL;

$json = $resp->json() ?? [];
echo "TOP_LEVEL_KEYS=" . implode(',', array_keys($json)) . PHP_EOL;
echo "CANDIDATES_COUNT=" . count($json['candidates'] ?? []) . PHP_EOL;
if (!empty($json['candidates'][0])) {
    $c = $json['candidates'][0];
    echo "FINISH_REASON=" . ($c['finishReason'] ?? 'N/A') . PHP_EOL;
    $parts = $c['content']['parts'] ?? [];
    echo "PARTS_COUNT=" . count($parts) . PHP_EOL;
    foreach ($parts as $i => $p) {
        echo "PART[$i]_KEYS=" . implode(',', array_keys($p)) . PHP_EOL;
    }
}
if (!empty($json['promptFeedback'])) {
    echo "PROMPT_FEEDBACK=" . json_encode($json['promptFeedback']) . PHP_EOL;
}
