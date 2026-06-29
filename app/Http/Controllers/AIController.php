<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\AIAnalysisService;
use App\Services\BookService;
use App\Services\IntentParserService;

class AIController extends Controller
{
    public function __construct(
        private AIAnalysisService $ai,
        private BookService $books,
        private IntentParserService $intentParser,
    ) {}

    public function chat(Request $request): JsonResponse
    {
        // [AI DEBUG] REQUEST_RECEIVED
        Log::info('[AI DEBUG] REQUEST_RECEIVED', [
            'method'       => $request->method(),
            'content_type' => $request->header('Content-Type', 'not-set'),
            'origin'       => $request->header('Origin', 'not-set'),
            'content_len'  => $request->header('Content-Length', 'not-set'),
        ]);

        $request->validate([
            'message'    => 'required|string|max:2000',
            'history'    => 'sometimes|array|max:20',
            'session_id' => 'sometimes|string|max:64',
        ]);

        $message       = trim($request->input('message'));
        $sessionId     = trim((string) $request->input('session_id', ''));
        $serverHistory = $this->loadHistory($sessionId);

        // [STEP 1] Request received
        Log::info('[AI TRACE] STEP 1 - Request received', [
            'message'        => $message,
            'session_id'     => $sessionId ?: '(none)',
            'server_history' => count($serverHistory),
        ]);

        // [AI DEBUG] REQUEST_BODY
        Log::info('[AI DEBUG] REQUEST_BODY', [
            'message_bytes'  => strlen($message),
            'message_mb'     => mb_strlen($message),
            'raw_preview'    => mb_substr($message, 0, 100),
            'server_history' => count($serverHistory),
        ]);

        // Server-side session takes priority; fall back to client-sent history for backward compat
        $historySource = !empty($serverHistory) ? $serverHistory : $request->input('history', []);

        // Build Gemini contents from conversation history + new user message
        $contents = [];
        foreach ($historySource as $h) {
            $role       = ($h['role'] === 'assistant') ? 'model' : 'user';
            $contents[] = ['role' => $role, 'parts' => [['text' => (string) $h['content']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        $systemPrompt = config('ai.system_prompt', '');
        $tools        = $this->getToolDeclarations();

        // PHP-only intent parsing — enriches search args without consuming Gemini quota
        $parsedIntent = $this->intentParser->parse($message);
        Log::info('[AI DEBUG] INTENT_PARSED', ['intent' => $parsedIntent]);

        // [STEP 2] Payload about to be sent to Gemini (Round 1)
        Log::info('[AI TRACE] STEP 2 - Sending to Gemini (Round 1)', [
            'contents_turns'    => count($contents),
            'tools_count'       => count($tools),
            'system_prompt_len' => strlen($systemPrompt),
        ]);

        try {
            // [AI DEBUG] CALL_AI_SERVICE Round1
            Log::info('[AI DEBUG] CALL_AI_SERVICE Round1', [
                'turns' => count($contents),
                'tools' => array_column($tools, 'name'),
            ]);

            // Round 1 — send user message to Gemini
            $resp1  = $this->ai->generate($contents, $tools, $systemPrompt);
            $parts1 = $resp1['candidates'][0]['content']['parts'] ?? [];
            $parsed = $this->ai->parseParts($parts1);

            // [AI DEBUG] AI_SERVICE_SUCCESS Round1
            Log::info('[AI DEBUG] AI_SERVICE_SUCCESS Round1', [
                'candidates'    => count($resp1['candidates'] ?? []),
                'finish_reason' => $resp1['candidates'][0]['finishReason'] ?? 'N/A',
                'parts_count'   => count($parts1),
                'top_level_keys' => array_keys($resp1),
            ]);

            // [STEP 3] Gemini Round 1 raw response
            Log::info('[AI TRACE] STEP 3 - Gemini Round 1 response', [
                'finish_reason'  => $resp1['candidates'][0]['finishReason'] ?? 'unknown',
                'parts_count'    => count($parts1),
                'parsed_summary' => array_map(fn ($p) => $p['type'] . ($p['type'] === 'functionCall' ? ':' . $p['name'] : ''), $parsed),
            ]);

            $functionCalls = array_values(array_filter($parsed, fn ($p) => $p['type'] === 'functionCall'));

            // [STEP 4] Did Gemini call a tool?
            Log::info('[AI TRACE] STEP 4 - Function calling decision', [
                'has_function_call'   => !empty($functionCalls),
                'function_call_count' => count($functionCalls),
            ]);

            if (empty($functionCalls)) {
                $textParts = array_filter($parsed, fn ($p) => $p['type'] === 'text');
                $reply     = implode('', array_column(array_values($textParts), 'text'));
                Log::info('[AI TRACE] STEP 9 - No tool call, returning direct text reply', [
                    'reply_length'  => strlen($reply),
                    'reply_preview' => mb_substr($reply, 0, 200),
                ]);
                $finalReply = $reply ?: 'Xin lỗi, tôi không thể trả lời lúc này.';
                Log::info('[AI DEBUG] RETURN_JSON', [
                    'http_status'   => 200,
                    'path'          => 'no-tool',
                    'reply_length'  => strlen($finalReply),
                    'reply_preview' => mb_substr($finalReply, 0, 200),
                ]);
                $this->saveHistory($sessionId, $serverHistory, $message, $finalReply);
                return response()->json(['reply' => $finalReply]);
            }

            // Execute the first function call
            $call = $functionCalls[0];

            // [STEP 5] Tool name
            Log::info('[AI TRACE] STEP 5 - Tool selected', [
                'tool_name' => $call['name'],
            ]);

            // Merge PHP-parsed intent into search_books args before execution
            if ($call['name'] === 'search_books') {
                $call['args'] = $this->mergeSearchArgs($call['args'], $parsedIntent);
                Log::info('[AI DEBUG] INTENT_MERGED', ['merged_args' => $call['args']]);
            }

            // [STEP 6] Tool arguments
            Log::info('[AI TRACE] STEP 6 - Tool arguments', [
                'args' => $call['args'],
            ]);

            $toolResult = $this->executeTool($call['name'], $call['args']);

            // [STEP 7] BookService / tool result
            Log::info('[AI TRACE] STEP 7 - Tool result', [
                'tool'   => $call['name'],
                'result' => $toolResult,
            ]);

            // Build model parts for Round 2 (include all parts Gemini returned).
            // Empty args must serialize as {} (JSON object), not [] (JSON array).
            // json_decode('{}', true) returns [] in PHP; json_encode([]) returns "[]" which
            // Gemini rejects because args is a proto Struct (must be JSON object).
            $modelParts = array_map(fn ($p) => match ($p['type']) {
                'functionCall' => ['functionCall' => ['name' => $p['name'], 'args' => empty($p['args']) ? new \stdClass() : $p['args']]],
                default        => ['text' => $p['text']],
            }, $parsed);

            $contents2   = $contents;
            $contents2[] = ['role' => 'model', 'parts' => $modelParts];
            $contents2[] = [
                'role'  => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name'     => $call['name'],
                        'response' => ['result' => $toolResult],
                    ],
                ]],
            ];

            // [AI DEBUG] CALL_AI_SERVICE Round2
            Log::info('[AI DEBUG] CALL_AI_SERVICE Round2', [
                'turns'     => count($contents2),
                'tool_used' => $call['name'],
            ]);

            // Round 2 — send tool result back to Gemini for final answer
            $resp2   = $this->ai->generate($contents2, $tools, $systemPrompt);
            $parts2  = $resp2['candidates'][0]['content']['parts'] ?? [];
            $parsed2 = $this->ai->parseParts($parts2);

            // [AI DEBUG] AI_SERVICE_SUCCESS Round2
            Log::info('[AI DEBUG] AI_SERVICE_SUCCESS Round2', [
                'candidates'    => count($resp2['candidates'] ?? []),
                'finish_reason' => $resp2['candidates'][0]['finishReason'] ?? 'N/A',
                'parts_count'   => count($parts2),
            ]);

            // [STEP 8] Gemini Round 2 raw response
            Log::info('[AI TRACE] STEP 8 - Gemini Round 2 response', [
                'finish_reason' => $resp2['candidates'][0]['finishReason'] ?? 'unknown',
                'parts_count'   => count($parts2),
                'parsed_types'  => array_column($parsed2, 'type'),
            ]);

            // Round 3 — tool chain: if Round 2 returned another function call, execute and ask again
            $functionCalls2 = array_values(array_filter($parsed2, fn ($p) => $p['type'] === 'functionCall'));

            if (!empty($functionCalls2)) {
                $call2 = $functionCalls2[0];

                Log::info('[AI TRACE] STEP 8b - Round 2 returned tool call (chaining)', [
                    'tool_name' => $call2['name'],
                ]);

                $toolResult2 = $this->executeTool($call2['name'], $call2['args']);

                Log::info('[AI TRACE] STEP 8c - Round 2 chained tool result', [
                    'tool'   => $call2['name'],
                    'result' => $toolResult2,
                ]);

                $modelParts2 = array_map(fn ($p) => match ($p['type']) {
                    'functionCall' => ['functionCall' => ['name' => $p['name'], 'args' => empty($p['args']) ? new \stdClass() : $p['args']]],
                    default        => ['text' => $p['text']],
                }, $parsed2);

                $contents3   = $contents2;
                $contents3[] = ['role' => 'model', 'parts' => $modelParts2];
                $contents3[] = [
                    'role'  => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name'     => $call2['name'],
                            'response' => ['result' => $toolResult2],
                        ],
                    ]],
                ];

                Log::info('[AI DEBUG] CALL_AI_SERVICE Round3', [
                    'turns'     => count($contents3),
                    'tool_used' => $call2['name'],
                ]);

                $resp3   = $this->ai->generate($contents3, $tools, $systemPrompt);
                $parts3  = $resp3['candidates'][0]['content']['parts'] ?? [];
                $parsed3 = $this->ai->parseParts($parts3);

                Log::info('[AI TRACE] STEP 9 - Final reply from Round 3', [
                    'finish_reason' => $resp3['candidates'][0]['finishReason'] ?? 'unknown',
                    'parts_count'   => count($parts3),
                ]);

                $textParts3 = array_filter($parsed3, fn ($p) => $p['type'] === 'text');
                $reply3     = implode('', array_column(array_values($textParts3), 'text'));
                $finalReply = $reply3 ?: 'Xin lỗi, không có kết quả.';

                Log::info('[AI DEBUG] RETURN_JSON', [
                    'http_status'   => 200,
                    'path'          => 'with-tool-chain',
                    'reply_length'  => strlen($finalReply),
                    'reply_preview' => mb_substr($finalReply, 0, 300),
                ]);
                $this->saveHistory($sessionId, $serverHistory, $message, $finalReply);
                return response()->json(['reply' => $finalReply]);
            }

            $textParts = array_filter($parsed2, fn ($p) => $p['type'] === 'text');
            $reply     = implode('', array_column(array_values($textParts), 'text'));

            // [STEP 9] Final JSON sent to frontend
            Log::info('[AI TRACE] STEP 9 - Final reply to frontend', [
                'reply_length'  => strlen($reply),
                'reply_preview' => mb_substr($reply, 0, 300),
            ]);

            // [AI DEBUG] RETURN_JSON
            $finalReply = $reply ?: 'Xin lỗi, không có kết quả.';
            Log::info('[AI DEBUG] RETURN_JSON', [
                'http_status'   => 200,
                'path'          => 'with-tool',
                'reply_length'  => strlen($finalReply),
                'reply_preview' => mb_substr($finalReply, 0, 300),
            ]);
            $this->saveHistory($sessionId, $serverHistory, $message, $finalReply);
            return response()->json(['reply' => $finalReply]);
        } catch (\Throwable $e) {
            if ($e->getCode() === 429) {
                Log::info('[AI DEBUG] RATE_LIMIT_429', ['message' => $e->getMessage()]);
                Log::info('[AI DEBUG] RETURN_JSON', ['http_status' => 200, 'path' => '429-rate-limit']);
                return response()->json([
                    'reply' => "Hệ thống AI đang bận do giới hạn lượt sử dụng.\nVui lòng thử lại sau khoảng 1 phút.",
                ]);
            }
            Log::info('[AI DEBUG] AI_SERVICE_EXCEPTION', [
                'class'   => get_class($e),
                'code'    => $e->getCode(),
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            Log::error('AI Chat Error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            Log::info('[AI DEBUG] RETURN_JSON', ['http_status' => 503, 'path' => 'exception']);
            return response()->json(['reply' => 'Xin lỗi, trợ lý AI tạm thời không khả dụng. Vui lòng thử lại sau.'], 503);
        }
    }

    private function getToolDeclarations(): array
    {
        return [
            [
                'name'        => 'search_books',
                'description' => 'Tìm kiếm sách trong thư viện. Dùng khi người dùng muốn tìm sách theo tên, tác giả, chủ đề, nghề nghiệp, mục tiêu học hoặc đặc điểm độc giả. Phân tích ý định, suy luận topic/target_reader/difficulty, tạo keywords[] (tiếng Việt và tiếng Anh). KHÔNG gọi tool này cho câu hỏi không liên quan đến sách hoặc thư viện.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query'          => [
                            'type'        => 'string',
                            'description' => 'Từ khóa đơn để tìm kiếm. Chỉ dùng khi người dùng nêu rõ tên sách hoặc tên tác giả.',
                        ],
                        'keywords'       => [
                            'type'        => 'array',
                            'items'       => ['type' => 'string'],
                            'description' => 'Danh sách từ khóa suy luận từ ý định người dùng, gồm cả tiếng Việt và tiếng Anh tương ứng. Tìm kiếm OR giữa các từ khóa. Dùng khi người dùng mô tả chủ đề, nhu cầu, nghề nghiệp hoặc lứa tuổi.',
                        ],
                        'topic'          => [
                            'type'        => 'string',
                            'description' => 'Chủ đề chính đã suy luận từ câu hỏi (ví dụ: "lãnh đạo", "lập trình Python", "tài chính cá nhân"). Được thêm vào tìm kiếm như một keyword.',
                        ],
                        'target_reader'  => [
                            'type'        => 'string',
                            'description' => 'Đối tượng đọc giả suy luận (ví dụ: "sinh viên", "trẻ 7 tuổi", "kỹ sư", "người mới đi làm"). Được thêm vào keywords.',
                        ],
                        'difficulty'     => [
                            'type'        => 'string',
                            'description' => 'Mức độ hoặc cấp độ sách (ví dụ: "nhập môn", "cơ bản", "nâng cao", "chuyên sâu"). Được thêm vào keywords.',
                        ],
                        'author'         => [
                            'type'        => 'string',
                            'description' => 'Tên tác giả nếu người dùng đề cập.',
                        ],
                        'category'       => [
                            'type'        => 'string',
                            'description' => 'Thể loại sách nếu người dùng đề cập rõ (ví dụ: "tiểu thuyết", "kỹ năng mềm", "khoa học").',
                        ],
                        'language'       => [
                            'type'        => 'string',
                            'description' => 'Ngôn ngữ sách: "vi" (tiếng Việt) hoặc "en" (tiếng Anh). Chỉ dùng khi người dùng đề cập ngôn ngữ cụ thể.',
                        ],
                        'available_only' => [
                            'type'        => 'boolean',
                            'description' => 'Chỉ trả về sách đang có sẵn để mượn.',
                        ],
                        'limit'          => [
                            'type'        => 'integer',
                            'description' => 'Số lượng kết quả tối đa (mặc định 5, tối đa 10).',
                        ],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name'        => 'get_book_detail',
                'description' => 'Lấy thông tin chi tiết đầy đủ của một cuốn sách: mô tả nội dung, tác giả, thể loại, nhà xuất bản, ngôn ngữ, đánh giá và số bản có thể mượn. Gọi tool này khi người dùng muốn: giới thiệu sách, tóm tắt nội dung, biết sách phù hợp với ai, xem đánh giá, điểm nổi bật, hoặc thông tin chi tiết. Cũng gọi ngay sau khi search_books đã trả về book_id trong cùng phiên hội thoại.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'book_id' => [
                            'type'        => 'integer',
                            'description' => 'ID của cuốn sách',
                        ],
                    ],
                    'required' => ['book_id'],
                ],
            ],
            [
                'name'        => 'check_book_availability',
                'description' => 'Kiểm tra số lượng bản sao có sẵn của một cuốn sách.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'book_id' => [
                            'type'        => 'integer',
                            'description' => 'ID của cuốn sách cần kiểm tra',
                        ],
                    ],
                    'required' => ['book_id'],
                ],
            ],
            [
                'name'        => 'get_library_policy',
                'description' => 'Lấy thông tin quy định của thư viện: giới hạn mượn sách, thời hạn mượn tối đa, phí phạt trả trễ.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
        ];
    }

    /**
     * Merge PHP-parsed intent with Gemini's function-call args for search_books.
     *
     * Keywords: union of Gemini's + PHP's (deduped).
     * Scalar fields (query, author, language, ...): PHP only fills blanks — Gemini's value takes priority.
     */
    private function mergeSearchArgs(array $geminiArgs, array $parsed): array
    {
        $merged = $geminiArgs;

        // Union keywords
        $phpKw   = array_values(array_filter($parsed['keywords'] ?? []));
        if (!empty($phpKw)) {
            $existing       = array_values(array_filter(array_map('trim', (array) ($merged['keywords'] ?? []))));
            $merged['keywords'] = array_values(array_unique(array_merge($existing, $phpKw)));
        }

        // Fill blank scalar fields from parsed intent
        foreach (['query', 'author', 'language', 'difficulty', 'target_reader', 'topic', 'category'] as $field) {
            if (($merged[$field] ?? '') === '' && ($parsed[$field] ?? '') !== '' && $parsed[$field] !== null) {
                $merged[$field] = $parsed[$field];
            }
        }

        return $merged;
    }

    private function executeTool(string $name, array $args): mixed
    {
        return match ($name) {
            'search_books'            => $this->toolSearchBooks($args),
            'get_book_detail'         => $this->toolGetBookDetail($args),
            'check_book_availability' => $this->toolCheckAvailability($args),
            'get_library_policy'      => $this->toolGetLibraryPolicy(),
            default                   => ['error' => "Không tìm thấy công cụ: $name"],
        };
    }

    private function toolSearchBooks(array $args): array
    {
        $query         = trim((string) ($args['query'] ?? ''));
        $keywords      = array_values(array_filter(array_map('trim', (array) ($args['keywords'] ?? []))));
        $availableOnly = (bool) ($args['available_only'] ?? false);
        $limit         = min((int) ($args['limit'] ?? 5), 10);
        $language      = trim((string) ($args['language'] ?? ''));
        $topic         = trim((string) ($args['topic'] ?? ''));

        // Merge all structured hints into keywords for OR search
        foreach (['topic', 'target_reader', 'difficulty', 'category', 'author'] as $field) {
            $val = trim((string) ($args[$field] ?? ''));
            if ($val !== '' && !in_array($val, $keywords, true)) {
                $keywords[] = $val;
            }
        }

        // Merge single query into keywords for unified multi-term OR search
        if ($query !== '' && !in_array($query, $keywords, true)) {
            array_unshift($keywords, $query);
        }

        if (empty($keywords)) {
            return ['error' => 'Thiếu từ khóa tìm kiếm.'];
        }

        $results = $this->books->searchBooks('', $availableOnly, $limit, $keywords, $language);

        if (empty($results)) {
            $searchedFor = $topic ?: $query ?: implode(', ', array_slice($keywords, 0, 3));
            return [
                'found'    => false,
                'message'  => "Không tìm thấy sách nào phù hợp với: \"$searchedFor\".",
                'searched' => $keywords,
                'topic'    => $topic,
            ];
        }

        return ['books' => $results, 'count' => count($results), 'found' => true];
    }

    private function toolGetBookDetail(array $args): array
    {
        $bookId = (int) ($args['book_id'] ?? 0);
        if ($bookId <= 0) {
            return ['error' => 'book_id không hợp lệ.'];
        }

        $book = $this->books->getBookDetail($bookId);
        if (!$book) {
            return ['error' => "Không tìm thấy sách với ID $bookId."];
        }

        return $book;
    }

    private function toolCheckAvailability(array $args): array
    {
        $bookId = (int) ($args['book_id'] ?? 0);
        if ($bookId <= 0) {
            return ['error' => 'book_id không hợp lệ.'];
        }

        $book = $this->books->getBookDetail($bookId);
        if (!$book) {
            return ['error' => "Không tìm thấy sách với ID $bookId."];
        }

        $copies = (int) $book['available_copies'];
        return [
            'book_id'          => $bookId,
            'title'            => $book['title'],
            'available_copies' => $copies,
            'is_available'     => $copies > 0,
            'message'          => $copies > 0
                ? "Sách \"{$book['title']}\" hiện có $copies bản có thể mượn."
                : "Sách \"{$book['title']}\" hiện không có bản nào để mượn.",
        ];
    }

    // -------------------------------------------------------------------------
    // M2-AI.7 — Server-side Conversation Memory (Cache-backed)
    // -------------------------------------------------------------------------

    private const HISTORY_LIMIT     = 10;
    private const CACHE_KEY_PREFIX  = 'ai_session_';
    private const CACHE_TTL_MINUTES = 120;

    private function loadHistory(string $sessionId): array
    {
        if (strlen($sessionId) < 8) {
            return [];
        }
        return Cache::get(self::CACHE_KEY_PREFIX . $sessionId, []);
    }

    private function saveHistory(string $sessionId, array $prior, string $userMsg, string $aiReply): void
    {
        if (strlen($sessionId) < 8) {
            return;
        }
        $ts        = now()->timestamp;
        $history   = $prior;
        $history[] = ['role' => 'user',      'content' => $userMsg,  'timestamp' => $ts];
        $history[] = ['role' => 'assistant', 'content' => $aiReply, 'timestamp' => $ts];

        if (count($history) > self::HISTORY_LIMIT) {
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }
        Cache::put(
            self::CACHE_KEY_PREFIX . $sessionId,
            $history,
            now()->addMinutes(self::CACHE_TTL_MINUTES)
        );
    }

    // -------------------------------------------------------------------------

    private function toolGetLibraryPolicy(): array
    {
        $s    = $this->books->getLibrarySettings();
        $base = ['borrow_limit', 'max_borrow_days', 'fine_per_day'];

        $result = [
            'borrow_limit'    => $s['borrow_limit'],
            'max_borrow_days' => $s['max_borrow_days'],
            'fine_per_day'    => $s['fine_per_day'],
        ];

        foreach ($s as $key => $value) {
            if (!in_array($key, $base, true)) {
                $result[$key] = $value;
            }
        }

        $result['description'] = sprintf(
            'Mỗi độc giả được mượn tối đa %d cuốn, thời hạn tối đa %d ngày. Phí phạt trả trễ: %s đồng/ngày/cuốn.',
            $s['borrow_limit'],
            $s['max_borrow_days'],
            number_format($s['fine_per_day'], 0, ',', '.'),
        );

        return $result;
    }
}
