<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        Log::info('[KEY_TRACE] generate() called', [
            'key_prefix'   => substr($this->apiKey, 0, 12) . '...',
            'key_length'   => strlen($this->apiKey),
            'endpoint'     => $this->endpoint,
            'config_key'   => substr(config('services.gemini.key') ?? '', 0, 12) . '...',
        ]);

        $response = Http::timeout(30)
            ->withHeaders(['Accept' => 'application/json'])
            ->withBody($body, 'application/json')
            ->post($this->endpoint . '?key=' . $this->apiKey);

        Log::info('[KEY_TRACE] Gemini HTTP response', [
            'status'         => $response->status(),
            'failed'         => $response->failed(),
            'candidates'     => count($response->json()['candidates'] ?? []),
            'top_keys'       => array_keys($response->json() ?? []),
            'body_preview'   => mb_substr($response->body(), 0, 500),
        ]);

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
            } elseif ($toolName === 'get_book_detail') {
                $title     = $result['title']            ?? 'Không rõ';
                $desc      = $result['description']      ?? '';
                $authors   = implode(', ', (array) ($result['authors']     ?? []));
                $cats      = implode(', ', (array) ($result['categories']  ?? []));
                $publisher = $result['publisher']        ?? '';
                $lang      = $result['language']         ?? '';
                $rating    = (float) ($result['avg_rating']    ?? 0);
                $reviews   = (int)   ($result['total_reviews'] ?? 0);
                $copies    = (int)   ($result['available_copies'] ?? 0);

                $langLabel = match ($lang) {
                    'vi'    => 'Tiếng Việt',
                    'en'    => 'Tiếng Anh',
                    default => $lang,
                };

                $lines = ["[MOCK] **{$title}**"];
                if ($authors !== '')   $lines[] = "Tác giả: {$authors}";
                if ($cats !== '')      $lines[] = "Thể loại: {$cats}";
                if ($publisher !== '') $lines[] = "Nhà xuất bản: {$publisher}";
                if ($langLabel !== '') $lines[] = "Ngôn ngữ: {$langLabel}";
                if ($desc !== '')      $lines[] = 'Giới thiệu: ' . mb_substr($desc, 0, 200) . (mb_strlen($desc) > 200 ? '...' : '');
                else                   $lines[] = 'Chưa có thông tin mô tả cho cuốn sách này.';
                if ($rating > 0)       $lines[] = 'Đánh giá: ' . number_format($rating, 1) . "/5 ({$reviews} lượt)";
                $lines[] = $copies > 0
                    ? "Hiện có {$copies} bản sẵn sàng cho mượn."
                    : 'Hiện không có bản nào để mượn.';

                $text = implode("\n", $lines);
            } elseif ($toolName === 'reserve_book') {
                $success = (bool) ($result['success'] ?? false);
                $title   = $result['title']   ?? 'sách này';
                $error   = $result['error']   ?? '';
                $msg     = $result['message'] ?? '';

                if ($success) {
                    $pos   = (int) ($result['queue_position'] ?? 1);
                    $resId = (int) ($result['reservation_id'] ?? 0);
                    $text  = "[MOCK] Đặt trước sách **{$title}** thành công!"
                           . " Bạn đang ở vị trí **{$pos}** trong hàng chờ."
                           . ' Chúng tôi sẽ thông báo khi sách sẵn sàng để mượn.'
                           . ($resId > 0 ? " (Mã đặt trước: #{$resId})" : '');
                } elseif ($error === 'already_reserved') {
                    $pos  = (int) ($result['queue_position'] ?? 0);
                    $text = "[MOCK] Bạn đã đặt trước sách **{$title}** rồi"
                          . ($pos > 0 ? " (vị trí {$pos} trong hàng chờ)" : '')
                          . '. Vui lòng chờ thông báo khi sách sẵn sàng.';
                } elseif ($error === 'book_available') {
                    $copies = (int) ($result['available_copies'] ?? 0);
                    $text   = "[MOCK] Sách **{$title}** hiện còn {$copies} bản có thể mượn trực tiếp tại quầy — bạn không cần đặt trước!";
                } elseif ($error === 'requires_auth') {
                    $text = '[MOCK] Bạn cần đăng nhập để sử dụng chức năng đặt trước sách.';
                } else {
                    $text = "[MOCK] Không thể đặt trước: {$msg}";
                }
            } elseif ($toolName === 'check_book_availability') {
                $title     = $result['title']            ?? 'Không rõ';
                $available = (int) ($result['available_copies'] ?? 0);
                $total     = (int) ($result['total_copies']     ?? 0);
                $borrowed  = (int) ($result['borrowed_copies']  ?? 0);
                $reserved  = (int) ($result['reserved_copies']  ?? 0);

                if ($available > 0) {
                    $text = "[MOCK] Sách **{$title}** hiện có {$available} bản sẵn sàng để mượn"
                          . " (tổng {$total} bản).";
                } else {
                    $text = "[MOCK] Sách **{$title}** hiện không có bản nào để mượn.";
                    if ($total > 0) {
                        $parts = [];
                        if ($borrowed > 0)  $parts[] = "{$borrowed} bản đang được mượn";
                        if ($reserved > 0)  $parts[] = "{$reserved} bản đang được đặt trước";
                        if (!empty($parts)) {
                            $text .= ' (' . implode(', ', $parts) . ', tổng ' . $total . ' bản).';
                        }
                        $text .= ' Bạn có thể đặt trước để được thông báo khi sách được trả lại.';
                    }
                }
            } elseif ($toolName === 'resolve_context_book') {
                $found  = (bool) ($result['found']   ?? false);
                $bookId = (int)  ($result['book_id'] ?? 0);

                if ($found && $bookId > 0) {
                    return [
                        'candidates' => [[
                            'content' => [
                                'parts' => [[
                                    'functionCall' => [
                                        'name' => 'reserve_book',
                                        'args' => ['book_id' => $bookId],
                                    ],
                                ]],
                            ],
                            'finishReason' => 'STOP',
                        ]],
                    ];
                }
                $text = '[MOCK] Tôi không tìm thấy thông tin sách nào trong lịch sử hội thoại. Bạn muốn đặt trước sách nào?';
            } else {
                // search_books result
                $found  = $result['found']  ?? false;
                $count  = (int) ($result['count'] ?? 0);
                $books  = $result['books']  ?? [];
                $topic  = $result['topic']  ?? '';
                $searched = implode(', ', array_slice((array) ($result['searched'] ?? []), 0, 3));

                // Chain to get_book_detail when search succeeded AND original question was intro/summary
                $originalUserText = '';
                foreach (array_reverse($contents) as $c) {
                    if (($c['role'] ?? '') === 'user' && isset($c['parts'][0]['text'])) {
                        $originalUserText = $c['parts'][0]['text'];
                        break;
                    }
                }

                if ($found && $count > 0 && !empty($books) && $this->isIntroQuestion($originalUserText)) {
                    $bookId = (int) ($books[0]['book_id'] ?? 0);
                    if ($bookId > 0) {
                        return [
                            'candidates' => [[
                                'content' => [
                                    'parts' => [[
                                        'functionCall' => [
                                            'name' => 'get_book_detail',
                                            'args' => ['book_id' => $bookId],
                                        ],
                                    ]],
                                ],
                                'finishReason' => 'STOP',
                            ]],
                        ];
                    }
                }

                if ($found && $count > 0 && !empty($books) && $this->isAvailabilityQuestion($originalUserText)) {
                    $bookId = (int) ($books[0]['book_id'] ?? 0);
                    if ($bookId > 0) {
                        return [
                            'candidates' => [[
                                'content' => [
                                    'parts' => [[
                                        'functionCall' => [
                                            'name' => 'check_book_availability',
                                            'args' => ['book_id' => $bookId],
                                        ],
                                    ]],
                                ],
                                'finishReason' => 'STOP',
                            ]],
                        ];
                    }
                }

                if ($found && $count > 0 && !empty($books) && $this->isReservationQuestion($originalUserText)) {
                    $bookId = (int) ($books[0]['book_id'] ?? 0);
                    if ($bookId > 0) {
                        return [
                            'candidates' => [[
                                'content' => [
                                    'parts' => [[
                                        'functionCall' => [
                                            'name' => 'reserve_book',
                                            'args' => ['book_id' => $bookId],
                                        ],
                                    ]],
                                ],
                                'finishReason' => 'STOP',
                            ]],
                        ];
                    }
                }

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

        if ($this->isAvailabilityQuestion($userText) && preg_match('/(?:id|sách số|book_id)\s*[#]?\s*(\d+)/iu', $userText, $m)) {
            return [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'check_book_availability',
                                'args' => ['book_id' => (int) $m[1]],
                            ],
                        ]],
                    ],
                    'finishReason' => 'STOP',
                ]],
            ];
        }

        if ($this->isReservationQuestion($userText) && preg_match('/(?:id|sách số|book_id)\s*[#]?\s*(\d+)/iu', $userText, $m)) {
            return [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'reserve_book',
                                'args' => ['book_id' => (int) $m[1]],
                            ],
                        ]],
                    ],
                    'finishReason' => 'STOP',
                ]],
            ];
        }

        if ($this->isReservationQuestion($userText) && $this->isContextualPronoun($userText)) {
            return [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'resolve_context_book',
                                'args' => [],
                            ],
                        ]],
                    ],
                    'finishReason' => 'STOP',
                ]],
            ];
        }

        if ($this->isIntroQuestion($userText) && preg_match('/(?:id|sách số|book_id)\s*[#]?\s*(\d+)/iu', $userText, $m)) {
            return [
                'candidates' => [[
                    'content' => [
                        'parts' => [[
                            'functionCall' => [
                                'name' => 'get_book_detail',
                                'args' => ['book_id' => (int) $m[1]],
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

    private function isReservationQuestion(string $text): bool
    {
        $lower    = mb_strtolower($text);
        $keywords = [
            'đặt trước', 'đặt sách', 'giữ sách', 'giữ giúp', 'reserve',
            'đăng ký mượn', 'xếp hàng chờ', 'muốn đặt', 'đặt cho tôi',
            'giữ cho tôi', 'đặt cuốn', 'đặt ngay',
        ];
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function isContextualPronoun(string $text): bool
    {
        $lower    = mb_strtolower($text);
        $patterns = [
            'cuốn đó', 'sách đó', 'cuốn này', 'sách này',
            'cuốn kia', 'sách kia', 'cuốn ấy', 'sách ấy',
            'quyển đó', 'quyển này', 'quyển kia',
            'cuốn sách đó', 'cuốn sách này',
        ];
        foreach ($patterns as $p) {
            if (str_contains($lower, $p)) {
                return true;
            }
        }
        return false;
    }

    private function isAvailabilityQuestion(string $text): bool
    {
        $lower    = mb_strtolower($text);
        $keywords = [
            'còn không', 'còn bản', 'còn để mượn', 'bản nào không',
            'hết sách', 'đã hết chưa', 'có thể mượn', 'mượn ngay',
            'bản sẵn', 'available', 'tình trạng sách', 'có sẵn không',
            'còn sẵn', 'hiện có', 'bản có sẵn',
        ];
        foreach ($keywords as $kw) {
            if (str_contains($lower, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function isIntroQuestion(string $text): bool
    {
        $lower    = mb_strtolower($text);
        $keywords = [
            'giới thiệu', 'tóm tắt', 'nói về sách', 'cho tôi biết về',
            'thông tin về sách', 'mô tả sách', 'cuốn sách này',
            'sách này', 'sách đó', 'về cuốn', 'nội dung sách',
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
