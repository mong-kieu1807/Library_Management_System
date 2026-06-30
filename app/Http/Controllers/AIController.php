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
    private string $currentSessionId = '';

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

        $message                = trim($request->input('message'));
        $sessionId              = trim((string) $request->input('session_id', ''));
        $this->currentSessionId = $sessionId;
        $serverHistory          = $this->loadHistory($sessionId);

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

        // Build Gemini contents from conversation history + new user message.
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

            $currentContents = $contents;
            $roundNum        = 1;
            $maxRounds       = 10;
            $finalReply      = 'Xin lỗi, tôi không thể trả lời lúc này.';

            while ($roundNum <= $maxRounds) {
                if (config('app.debug')) {
                    Log::debug('[AI GEMINI] Round ' . $roundNum . ' REQUEST', [
                        'turns_count'   => count($currentContents),
                        'turns_summary' => array_map(fn ($t) => [
                            'role'       => $t['role'],
                            'part_types' => array_map('array_key_first', (array) ($t['parts'] ?? [])),
                        ], $currentContents),
                        'last_user_text' => (function () use ($currentContents): string {
                            foreach (array_reverse($currentContents) as $t) {
                                if (($t['role'] ?? '') === 'user' && isset($t['parts'][0]['text'])) {
                                    return mb_substr($t['parts'][0]['text'], 0, 300);
                                }
                            }
                            return '';
                        })(),
                    ]);
                }

                $resp   = $this->ai->generate($currentContents, $tools, $systemPrompt);
                $parts  = $resp['candidates'][0]['content']['parts'] ?? [];
                $parsed = $this->ai->parseParts($parts);

                if (config('app.debug')) {
                    $dbgFc  = array_values(array_filter($parsed, fn ($p) => $p['type'] === 'functionCall'));
                    $dbgTxt = array_values(array_filter($parsed, fn ($p) => $p['type'] === 'text'));
                    Log::debug('[AI GEMINI] Round ' . $roundNum . ' RESPONSE', [
                        'finish_reason'  => $resp['candidates'][0]['finishReason'] ?? 'N/A',
                        'raw_parts'      => $parts,
                        'parsed_parts'   => $parsed,
                        'function_calls' => array_map(fn ($p) => ['name' => $p['name'], 'args' => $p['args']], $dbgFc),
                        'text_parts'     => array_map(fn ($p) => mb_substr($p['text'], 0, 500), $dbgTxt),
                    ]);
                }

                Log::info('[AI DEBUG] AI_SERVICE_SUCCESS Round' . $roundNum, [
                    'candidates'     => count($resp['candidates'] ?? []),
                    'finish_reason'  => $resp['candidates'][0]['finishReason'] ?? 'N/A',
                    'parts_count'    => count($parts),
                    'top_level_keys' => array_keys($resp),
                ]);

                Log::info('[AI TRACE] Round ' . $roundNum . ' response', [
                    'finish_reason' => $resp['candidates'][0]['finishReason'] ?? 'unknown',
                    'parts_count'   => count($parts),
                    'parsed_types'  => array_map(
                        fn ($p) => $p['type'] . ($p['type'] === 'functionCall' ? ':' . $p['name'] : ''),
                        $parsed
                    ),
                ]);

                $functionCalls = array_values(array_filter($parsed, fn ($p) => $p['type'] === 'functionCall'));

                if (empty($functionCalls)) {
                    $textParts  = array_filter($parsed, fn ($p) => $p['type'] === 'text');
                    $reply      = implode('', array_column(array_values($textParts), 'text'));
                    $finalReply = $reply ?: ($roundNum === 1
                        ? 'Xin lỗi, tôi không thể trả lời lúc này.'
                        : 'Xin lỗi, không có kết quả.');
                    break;
                }

                $call = $functionCalls[0];

                Log::info('[AI TRACE] Round ' . $roundNum . ' - tool selected', [
                    'tool_name' => $call['name'],
                    'args'      => $call['args'],
                ]);

                if ($call['name'] === 'search_books') {
                    $call['args'] = $this->mergeSearchArgs($call['args'], $parsedIntent);
                    Log::info('[AI DEBUG] INTENT_MERGED', ['merged_args' => $call['args']]);
                }

                $toolResult = $this->executeTool($call['name'], $call['args']);

                Log::info('[AI TRACE] Round ' . $roundNum . ' - tool result', [
                    'tool'   => $call['name'],
                    'result' => $toolResult,
                ]);

                // Empty args must serialize as {} (JSON object), not [] (JSON array).
                // json_decode('{}', true) returns [] in PHP; json_encode([]) returns "[]" which
                // Gemini rejects because args is a proto Struct (must be JSON object).
                $modelParts = array_map(fn ($p) => match ($p['type']) {
                    'functionCall' => ['functionCall' => [
                        'name' => $p['name'],
                        'args' => empty($p['args']) ? new \stdClass() : $p['args'],
                    ]],
                    default => ['text' => $p['text']],
                }, $parsed);

                $currentContents[] = ['role' => 'model', 'parts' => $modelParts];
                $currentContents[] = [
                    'role'  => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name'     => $call['name'],
                            'response' => ['result' => $toolResult],
                        ],
                    ]],
                ];

                $roundNum++;
            }

            Log::info('[AI TRACE] STEP 9 - Final reply', [
                'rounds'        => $roundNum,
                'reply_length'  => strlen($finalReply),
                'reply_preview' => mb_substr($finalReply, 0, 300),
            ]);

            Log::info('[AI DEBUG] RETURN_JSON', [
                'http_status'   => 200,
                'path'          => $roundNum === 1 ? 'no-tool' : 'with-tool-chain',
                'rounds'        => $roundNum,
                'reply_length'  => strlen($finalReply),
                'reply_preview' => mb_substr($finalReply, 0, 300),
            ]);

            $bookContext = $this->extractBookContextFromContents($currentContents);
            $this->saveHistory($sessionId, $serverHistory, $message, $finalReply, $bookContext);
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
                'description' => 'Kiểm tra tình trạng thực tế của sách: số bản có sẵn (available), đang được mượn (borrowed), đang được đặt trước (reserved), và tổng số bản. Gọi tool này khi người dùng hỏi: sách còn không, còn bản nào để mượn không, sách đã hết chưa, có thể mượn ngay không, tình trạng sách hiện tại, bao nhiêu bản sẵn. KHÔNG tự trả lời dựa trên dữ liệu cũ — luôn gọi tool để lấy thông tin realtime từ DB. Nếu chưa biết book_id, hãy gọi search_books trước để lấy book_id, rồi mới gọi check_book_availability.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'book_id' => [
                            'type'        => 'integer',
                            'description' => 'ID của cuốn sách cần kiểm tra tình trạng',
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
            [
                'name'        => 'resolve_context_book',
                'description' => 'Lấy thông tin sách đang được nhắc đến trong ngữ cảnh hội thoại hiện tại (server session). '
                               . 'Gọi tool này khi người dùng dùng đại từ chỉ sách ("cuốn đó", "sách này", "cuốn vừa hỏi", "cuốn kia", "quyển đó") '
                               . 'và cần book_id để gọi reserve_book. '
                               . 'Không cần tham số — backend tự đọc session. '
                               . 'Nếu found=true: gọi reserve_book(book_id) ngay. '
                               . 'Nếu found=false: hỏi người dùng muốn đặt sách nào.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                    'required'   => [],
                ],
            ],
            [
                'name'        => 'reserve_book',
                'description' => 'Đặt trước sách cho người dùng đang đăng nhập. Gọi tool này khi người dùng muốn: đặt trước sách, giữ sách, reserve, giữ giúp, đăng ký chờ mượn. Tool tự lấy user_id từ phiên đăng nhập — KHÔNG được hỏi user_id hay thông tin cá nhân. Nếu chưa biết book_id và người dùng nêu tên sách rõ ràng: gọi search_books trước để lấy book_id, rồi gọi reserve_book(book_id). Nếu người dùng dùng đại từ ("cuốn đó", "sách này"): gọi resolve_context_book trước để lấy book_id. KHÔNG tự trả lời — luôn gọi tool.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'book_id' => [
                            'type'        => 'integer',
                            'description' => 'ID của cuốn sách cần đặt trước. Lấy từ kết quả search_books hoặc resolve_context_book trong phiên hội thoại hiện tại.',
                        ],
                    ],
                    'required' => ['book_id'],
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
            'resolve_context_book'    => $this->toolResolveContextBook(),
            'reserve_book'            => $this->toolReserveBook($args),
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

        $data = $this->books->getBookAvailability($bookId);
        if (!$data) {
            return ['error' => "Không tìm thấy sách với ID $bookId."];
        }

        return [
            'book_id'          => $data['book_id'],
            'title'            => $data['title'],
            'total_copies'     => $data['total_copies'],
            'available_copies' => $data['available_copies'],
            'borrowed_copies'  => $data['borrowed_copies'],
            'reserved_copies'  => $data['reserved_copies'],
            'is_available'     => $data['is_available'],
            'message'          => $data['is_available']
                ? "Sách \"{$data['title']}\" hiện có {$data['available_copies']} bản có thể mượn (tổng {$data['total_copies']} bản)."
                : "Sách \"{$data['title']}\" hiện không có bản nào để mượn (tổng {$data['total_copies']} bản, đang mượn {$data['borrowed_copies']}, đặt trước {$data['reserved_copies']}).",
        ];
    }

    private function toolReserveBook(array $args): array
    {
        $bookId = (int) ($args['book_id'] ?? 0);
        if ($bookId <= 0) {
            return [
                'success' => false,
                'error'   => 'invalid_book_id',
                'message' => 'Không xác định được sách cần đặt trước. Vui lòng nêu tên hoặc ID sách.',
            ];
        }

        $userId = auth('sanctum')->id();
        if (!$userId) {
            return [
                'success' => false,
                'error'   => 'requires_auth',
                'message' => 'Bạn cần đăng nhập để sử dụng chức năng đặt trước sách.',
            ];
        }

        return $this->books->createReservation((int) $userId, $bookId);
    }

    private function toolResolveContextBook(): array
    {
        $history = Cache::get(self::CACHE_KEY_PREFIX . $this->currentSessionId, []);
        foreach (array_reverse($history) as $entry) {
            if (!empty($entry['last_book_id'])) {
                return [
                    'found'   => true,
                    'book_id' => (int) $entry['last_book_id'],
                    'title'   => (string) ($entry['last_book_title'] ?? ''),
                ];
            }
        }
        return [
            'found'   => false,
            'book_id' => 0,
            'title'   => '',
            'message' => 'Không tìm thấy thông tin sách từ lịch sử hội thoại. Vui lòng cho biết tên sách muốn đặt trước.',
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

    private function saveHistory(string $sessionId, array $prior, string $userMsg, string $aiReply, array $bookContext = []): void
    {
        if (strlen($sessionId) < 8) {
            return;
        }
        $ts        = now()->timestamp;
        $history   = $prior;
        $history[] = ['role' => 'user', 'content' => $userMsg, 'timestamp' => $ts];
        $entry     = ['role' => 'assistant', 'content' => $aiReply, 'timestamp' => $ts];
        if (!empty($bookContext['last_book_id'])) {
            $entry['last_book_id']    = (int)    $bookContext['last_book_id'];
            $entry['last_book_title'] = (string) ($bookContext['last_book_title'] ?? '');
        }
        $history[] = $entry;

        if (count($history) > self::HISTORY_LIMIT) {
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }
        Cache::put(
            self::CACHE_KEY_PREFIX . $sessionId,
            $history,
            now()->addMinutes(self::CACHE_TTL_MINUTES)
        );
    }

    // Scans $currentContents in reverse for the most-recent functionResponse whose
    // book context is unambiguous (Gemini confirmed a single specific book). Priority:
    //   1. reserve_book           — book_id in result (Gemini already committed)
    //   2. get_book_detail        — book_id in result (Gemini fetched a specific book)
    //   3. check_book_availability — book_id in result (Gemini confirmed a specific book)
    //   4. search_books            — ONLY when found==true && count==1 (single match)
    //
    // Multi-result search_books is intentionally ignored: books[0] may not be the
    // book Gemini discussed in its reply, so saving it would encode wrong context.
    private function extractBookContextFromContents(array $contents): array
    {
        foreach (array_reverse($contents) as $turn) {
            foreach ((array) ($turn['parts'] ?? []) as $part) {
                $fr = $part['functionResponse'] ?? null;
                if (!$fr) {
                    continue;
                }
                $toolName = (string) ($fr['name'] ?? '');
                $result   = $fr['response']['result'] ?? null;
                if (!$result) {
                    continue;
                }

                // Unambiguous single-book tools — always save
                if (in_array($toolName, ['reserve_book', 'get_book_detail', 'check_book_availability', 'resolve_context_book'], true)) {
                    $bookId = (int) ($result['book_id'] ?? 0);
                    if ($bookId > 0) {
                        return [
                            'last_book_id'    => $bookId,
                            'last_book_title' => (string) ($result['title'] ?? ''),
                        ];
                    }
                }

                // Search result — only when exactly one book matched (unambiguous)
                if ($toolName === 'search_books') {
                    $found = (bool) ($result['found'] ?? false);
                    $count = (int)  ($result['count'] ?? 0);
                    if ($found && $count === 1 && !empty($result['books'][0]['book_id'])) {
                        return [
                            'last_book_id'    => (int)    $result['books'][0]['book_id'],
                            'last_book_title' => (string) ($result['books'][0]['title'] ?? ''),
                        ];
                    }
                    // Multiple results or not found — skip, do not encode ambiguous context
                }
            }
        }
        return [];
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
