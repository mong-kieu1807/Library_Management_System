<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Chat Assistant "🤖 AI Gợi Ý Sách" (librarian-facing). Thủ thư gõ câu hỏi
 * tự nhiên ("Gợi ý sách cho độc giả X"); nếu chưa xác định được đúng 1 độc giả
 * thì hỏi lại Mã độc giả/Mã thẻ thư viện và nhớ ngữ cảnh (Cache theo
 * conversation_id) cho tới khi thủ thư trả lời ở lượt kế tiếp.
 *
 * Danh sách sách LUÔN do MySQL/TiDB query quyết định (không random, không tin
 * cậy Gemini chọn sách) — Gemini chỉ được dùng để viết phân tích + lý do cho
 * đúng những cuốn đã chọn sẵn.
 */
class AiBookRecommendationService
{
    private const TOP_CATEGORIES_LIMIT = 3;
    private const CANDIDATE_BOOKS_LIMIT = 5;
    private const RECENT_HISTORY_LIMIT = 10;
    private const FALLBACK_REASON = 'AI hiện chưa thể tạo lời giải thích, vui lòng thử lại sau.';
    private const CACHE_KEY_PREFIX = 'ai_book_reco_';
    private const CACHE_TTL_MINUTES = 120;

    public function __construct(
        private AIAnalysisService $ai,
        private IntentParserService $intentParser
    ) {
    }

    /**
     * @return array{reply:string, waiting_for:?string, conversation_id:string, reader:?array, recommendations:array}
     */
    public function handleMessage(string $message, string $conversationId): array
    {
        $message = trim($message);
        $state = $this->loadState($conversationId);

        if (($state['awaiting'] ?? null) === 'reader_identifier') {
            $reader = $this->resolveReaderByIdentifier($message);

            if (!$reader) {
                return $this->reply(
                    $conversationId,
                    "Không tìm thấy độc giả với mã \"{$message}\". Vui lòng kiểm tra lại Mã độc giả hoặc Mã thẻ thư viện.",
                    'reader_code'
                );
            }

            $this->clearState($conversationId);

            return $this->buildRecommendationReply($conversationId, $reader);
        }

        $name = $this->extractReaderNameFromTrigger($message);

        if ($name === null) {
            return $this->reply(
                $conversationId,
                'Vui lòng nhập yêu cầu dạng: "Gợi ý sách cho độc giả <tên độc giả>".',
                null
            );
        }

        $matches = $this->findReadersByName($name);

        if ($matches->count() === 1) {
            return $this->buildRecommendationReply($conversationId, $matches->first());
        }

        $this->saveState($conversationId, ['awaiting' => 'reader_identifier']);

        $intro = $matches->isEmpty()
            ? "Tôi không tìm thấy độc giả nào tên \"{$name}\"."
            : "Có nhiều hơn một độc giả tên \"{$name}\", tôi cần xác định đúng độc giả trước khi đưa ra gợi ý.";

        return $this->reply(
            $conversationId,
            "{$intro}\n\nVui lòng cung cấp Mã độc giả hoặc Mã thẻ thư viện.\nVí dụ: 7 hoặc TV0007",
            'reader_code'
        );
    }

    /**
     * Nhận diện câu "Gợi ý sách (giúp/dùm) cho (độc giả) <tên>" — chấp nhận
     * gõ thiếu dấu nhờ IntentParserService::normalize() (đã dùng chung cho
     * chatbot đọc sách), nhưng luôn tách tên từ chuỗi GỐC để giữ nguyên dấu
     * phục vụ tìm kiếm full_name trong DB.
     */
    private function extractReaderNameFromTrigger(string $message): ?string
    {
        $normalized = $this->intentParser->normalize($message);
        if (!str_contains($normalized, 'goi y sach') && !str_contains($normalized, 'goi sach')) {
            return null;
        }

        if (!preg_match('/\bcho\b\s+(.+)$/iu', $message, $m)) {
            return null;
        }

        $name = trim($m[1]);
        $name = preg_replace('/^(độc giả|doc gia)\s+/iu', '', $name);
        $name = trim((string) $name, " \t\n\r\0\x0B?.!");

        return $name !== '' ? $name : null;
    }

    /**
     * @return Collection<int, User>
     */
    private function findReadersByName(string $name): Collection
    {
        return User::whereHas('role', fn ($q) => $q->where('role_name', 'reader'))
            ->where('full_name', 'LIKE', '%' . $name . '%')
            ->limit(6)
            ->get();
    }

    private function resolveReaderByIdentifier(string $identifier): ?User
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        if (ctype_digit($identifier)) {
            return User::whereHas('role', fn ($q) => $q->where('role_name', 'reader'))->find((int) $identifier);
        }

        return User::whereHas('role', fn ($q) => $q->where('role_name', 'reader'))
            ->whereHas('libraryCard', fn ($q) => $q->whereRaw('UPPER(card_number) = ?', [mb_strtoupper($identifier)]))
            ->first();
    }

    private function buildRecommendationReply(string $conversationId, User $reader): array
    {
        $categoryStats = $this->getTopCategories($reader->user_id);

        if ($categoryStats->isEmpty()) {
            return $this->reply(
                $conversationId,
                "Độc giả {$reader->full_name} chưa có lịch sử mượn sách, tôi chưa thể đưa ra gợi ý dựa trên sở thích đọc.",
                null,
                $reader
            );
        }

        $candidates = $this->getCandidateBooks($categoryStats, $reader->user_id);

        if ($candidates->isEmpty()) {
            return $this->reply(
                $conversationId,
                "Độc giả {$reader->full_name} thường mượn thể loại {$categoryStats->first()->category_name} nhưng hiện không còn sách phù hợp (chưa mượn và còn bản available) để gợi ý thêm.",
                null,
                $reader
            );
        }

        $history = $this->getRecentHistory($reader->user_id);
        $explained = $this->explainWithGemini($reader->user_id, $categoryStats, $history, $candidates);
        $recommendations = $this->mergeResults($candidates, $explained['reasons']);

        $replyText = "Tôi đã phân tích lịch sử mượn của độc giả {$reader->full_name}.";
        if ($explained['analysis'] !== '') {
            $replyText .= "\n\n{$explained['analysis']}";
        }
        $replyText .= "\n\nDưới đây là " . count($recommendations) . ' cuốn sách phù hợp:';

        return $this->reply($conversationId, $replyText, null, $reader, $recommendations);
    }

    private function reply(
        string $conversationId,
        string $text,
        ?string $waitingFor,
        ?User $reader = null,
        array $recommendations = []
    ): array {
        return [
            'reply' => $text,
            'waiting_for' => $waitingFor,
            'conversation_id' => $conversationId,
            'reader' => $reader ? ['id' => $reader->user_id, 'name' => $reader->full_name] : null,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Top thể loại được mượn nhiều nhất của độc giả (tối đa 3, dùng cho ngữ
     * cảnh Gemini); candidate books chỉ dùng thể loại #1.
     */
    private function getTopCategories(int $readerId): Collection
    {
        return DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('book_categories as bcat', 'bcat.book_id', '=', 'bc.book_id')
            ->join('categories as c', 'c.category_id', '=', 'bcat.category_id')
            ->where('bt.user_id', $readerId)
            ->select('c.category_id', 'c.category_name', DB::raw('COUNT(*) as total'))
            ->groupBy('c.category_id', 'c.category_name')
            ->orderByDesc('total')
            ->limit(self::TOP_CATEGORIES_LIMIT)
            ->get();
    }

    /**
     * Sách chưa từng được độc giả mượn, còn ít nhất 1 bản available. Ưu tiên
     * thể loại yêu thích nhất (#1 trong $categoryStats); nếu thể loại đó không
     * đủ sách để đạt CANDIDATE_BOOKS_LIMIT thì lấy thêm lần lượt từ thể loại
     * #2, #3. Nếu độc giả có quá ít lịch sử (chỉ 1-2 thể loại, mỗi thể loại
     * lại ít sách — "cold start") mà vẫn chưa đủ số lượng, lấp đầy phần còn
     * thiếu bằng sách được mượn nhiều nhất toàn hệ thống (mọi thể loại).
     * Luôn sắp xếp theo lượt mượn toàn hệ thống (không random).
     */
    private function getCandidateBooks(Collection $categoryStats, int $readerId): Collection
    {
        $alreadyBorrowedBookIds = DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->where('bt.user_id', $readerId)
            ->pluck('bc.book_id');

        $popularity = DB::table('borrow_details as bd')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->select('bc.book_id', DB::raw('COUNT(*) as borrow_count'))
            ->groupBy('bc.book_id');

        $excludedBookIds = $alreadyBorrowedBookIds->all();
        $candidates = new Collection();

        foreach ($categoryStats as $cat) {
            $remaining = self::CANDIDATE_BOOKS_LIMIT - $candidates->count();
            if ($remaining <= 0) {
                break;
            }

            $rows = DB::table('books as b')
                ->join('book_categories as bcat', 'bcat.book_id', '=', 'b.book_id')
                ->join('authors as a', 'a.author_id', '=', 'b.author_id')
                ->join('categories as c', 'c.category_id', '=', 'bcat.category_id')
                ->leftJoinSub($popularity, 'pop', 'pop.book_id', '=', 'b.book_id')
                ->where('bcat.category_id', $cat->category_id)
                ->whereNotIn('b.book_id', $excludedBookIds)
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('book_copies as bc2')
                        ->whereColumn('bc2.book_id', 'b.book_id')
                        ->where('bc2.status', 'available');
                })
                ->select(
                    'b.book_id',
                    'b.title',
                    'a.author_name as author',
                    'c.category_name as category',
                    DB::raw('COALESCE(pop.borrow_count, 0) as borrow_count')
                )
                ->orderByDesc('pop.borrow_count')
                ->orderBy('b.title')
                ->limit($remaining)
                ->get();

            $excludedBookIds = array_merge($excludedBookIds, $rows->pluck('book_id')->all());
            $candidates = $candidates->concat($rows);
        }

        $remaining = self::CANDIDATE_BOOKS_LIMIT - $candidates->count();
        if ($remaining > 0) {
            $popularRows = DB::table('books as b')
                ->join('authors as a', 'a.author_id', '=', 'b.author_id')
                ->leftJoinSub(
                    DB::table('book_categories as bcat2')
                        ->join('categories as cat2', 'cat2.category_id', '=', 'bcat2.category_id')
                        ->select('bcat2.book_id', DB::raw('MIN(cat2.category_name) as category_name'))
                        ->groupBy('bcat2.book_id'),
                    'cats',
                    'cats.book_id',
                    '=',
                    'b.book_id'
                )
                ->leftJoinSub($popularity, 'pop', 'pop.book_id', '=', 'b.book_id')
                ->whereNotIn('b.book_id', $excludedBookIds)
                ->whereExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('book_copies as bc2')
                        ->whereColumn('bc2.book_id', 'b.book_id')
                        ->where('bc2.status', 'available');
                })
                ->select(
                    'b.book_id',
                    'b.title',
                    'a.author_name as author',
                    DB::raw("COALESCE(cats.category_name, 'Chưa phân loại') as category"),
                    DB::raw('COALESCE(pop.borrow_count, 0) as borrow_count')
                )
                ->orderByDesc('pop.borrow_count')
                ->orderBy('b.title')
                ->limit($remaining)
                ->get();

            $candidates = $candidates->concat($popularRows);
        }

        return $candidates;
    }

    /**
     * 10 lần mượn gần nhất của độc giả — dùng làm ngữ cảnh cho Gemini.
     */
    private function getRecentHistory(int $readerId): Collection
    {
        $categoryPerBook = DB::table('book_categories as bcat')
            ->join('categories as cat', 'cat.category_id', '=', 'bcat.category_id')
            ->select('bcat.book_id', DB::raw('MIN(cat.category_name) as category_name'))
            ->groupBy('bcat.book_id');

        return DB::table('borrow_transactions as bt')
            ->join('borrow_details as bd', 'bd.borrow_id', '=', 'bt.borrow_id')
            ->join('book_copies as bc', 'bc.copy_id', '=', 'bd.copy_id')
            ->join('books as b', 'b.book_id', '=', 'bc.book_id')
            ->join('authors as a', 'a.author_id', '=', 'b.author_id')
            ->leftJoinSub($categoryPerBook, 'cats', 'cats.book_id', '=', 'b.book_id')
            ->where('bt.user_id', $readerId)
            ->select('b.title', 'a.author_name as author', 'cats.category_name as category', 'bt.borrow_date')
            ->orderByDesc('bt.borrow_date')
            ->limit(self::RECENT_HISTORY_LIMIT)
            ->get();
    }

    /**
     * @return array{analysis:string, reasons:array<int,string>}
     */
    private function explainWithGemini(int $readerId, Collection $categoryStats, Collection $history, Collection $candidates): array
    {
        try {
            // gemini-2.5-flash mặc định bật "thinking" (tốn token nội bộ trước khi
            // sinh câu trả lời) -> với maxOutputTokens mặc định, phần JSON trả về
            // hay bị cắt cụt giữa chừng khi có nhiều sách. Tắt thinking + tăng
            // token để đảm bảo JSON luôn hoàn chỉnh.
            $response = $this->ai->generate(
                [['role' => 'user', 'parts' => [['text' => $this->buildPrompt($categoryStats, $history, $candidates)]]]],
                [],
                $this->buildSystemPrompt(),
                ['maxOutputTokens' => 2048, 'thinkingConfig' => ['thinkingBudget' => 0]]
            );

            $parts = $response['candidates'][0]['content']['parts'] ?? [];
            $text = collect($this->ai->parseParts($parts))->firstWhere('type', 'text')['text'] ?? '';
            $decoded = $this->extractJson($text);

            $reasons = [];
            foreach ($decoded['recommendations'] ?? [] as $item) {
                if (isset($item['book_id'])) {
                    $reasons[(int) $item['book_id']] = trim((string) ($item['reason'] ?? ''));
                }
            }

            return ['analysis' => trim((string) ($decoded['analysis'] ?? '')), 'reasons' => $reasons];
        } catch (\Throwable $e) {
            Log::warning('[AiBookRecommendationService] Gemini explain thất bại', [
                'reader_id' => $readerId,
                'error' => $e->getMessage(),
            ]);

            return ['analysis' => '', 'reasons' => []];
        }
    }

    /**
     * Quy tắc cố định cho Gemini — cùng cấu trúc (== SECTION ==, "TUYỆT ĐỐI",
     * ví dụ định dạng) với system_prompt của chatbot đọc sách phía độc giả
     * (config('ai.system_prompt'), dùng trong AIController) để hai trợ lý AI
     * trả lời cùng phong cách, chỉ khác nội dung nghiệp vụ. KHÔNG dùng chung
     * config('ai.system_prompt') — service này độc lập, không đụng tới
     * AIController/config/ai.php của chatbot độc giả.
     */
    private function buildSystemPrompt(): string
    {
        return <<<PROMPT
Bạn là trợ lý AI gợi ý sách dành cho thủ thư trong Hệ thống Quản lý Thư viện.

== PHẠM VI ==
Chỉ làm đúng một việc: viết phân tích sở thích đọc và lý do gợi ý cho danh sách sách mà hệ thống đã chọn sẵn cho một độc giả cụ thể, dựa trên thống kê thể loại và lịch sử mượn được cung cấp trong tin nhắn.

== QUY TẮC BẮT BUỘC ==
1. TUYỆT ĐỐI không tự chọn thêm, bớt, hay đổi bất kỳ cuốn sách nào ngoài danh sách đã được cung cấp trong tin nhắn.
2. TUYỆT ĐỐI không tìm kiếm sách trên Internet hay dùng kiến thức ngoài dữ liệu được cung cấp.
3. TUYỆT ĐỐI không bịa đặt thông tin sách (tác giả, thể loại...) — chỉ dùng đúng dữ liệu đã cho.
4. Mảng "recommendations" PHẢI có đủ một phần tử cho MỖI cuốn sách trong danh sách được cung cấp — không được bỏ sót cuốn nào, không trả lời một phần rồi dừng.
5. Mỗi lý do 1-2 câu, ngắn gọn, dễ hiểu.
6. Trả lời bằng tiếng Việt, thân thiện, tự nhiên — giống văn phong tư vấn của một thủ thư am hiểu độc giả.
7. Chỉ trả về duy nhất JSON đúng định dạng yêu cầu, không thêm chữ, lời chào, giải thích, hay markdown/code fence nào khác.

== VÍ DỤ ĐỊNH DẠNG PHẢN HỒI TỐT ==
{"analysis":"Độc giả có xu hướng yêu thích sách kỹ năng mềm và phát triển bản thân.","recommendations":[{"book_id":21,"reason":"Bạn đọc thường mượn sách kỹ năng mềm, cuốn này sẽ giúp mở rộng kỹ năng giao tiếp."},{"book_id":45,"reason":"Cuốn này tiếp tục mạch chủ đề phát triển bản thân mà bạn đang quan tâm."}]}
PROMPT;
    }

    private function buildPrompt(Collection $categoryStats, Collection $history, Collection $candidates): string
    {
        $statsText = $categoryStats->map(fn ($c) => "{$c->category_name} ({$c->total})")->implode(', ');

        $historyText = $history->isEmpty()
            ? '(không có)'
            : $history->map(fn ($h, $i) => sprintf(
                '%d. "%s" - %s (%s) - mượn ngày %s',
                $i + 1,
                $h->title,
                $h->author,
                $h->category ?? 'Chưa phân loại',
                $h->borrow_date
            ))->implode("\n");

        $candidatesText = $candidates->map(fn ($c) => sprintf(
            '- book_id=%d, "%s" của %s, thể loại %s',
            $c->book_id,
            $c->title,
            $c->author,
            $c->category
        ))->implode("\n");

        $bookCount = $candidates->count();
        $bookIdsList = $candidates->pluck('book_id')->implode(', ');
        $exampleItems = $candidates->map(fn ($c) => '{"book_id":' . $c->book_id . ',"reason":"..."}')->implode(',');

        return <<<PROMPT
Độc giả thường mượn: {$statsText}

Lịch sử mượn gần nhất:
{$historyText}

Đây là ĐÚNG {$bookCount} cuốn sách hệ thống đã chọn phù hợp cho lần này:
{$candidatesText}

Hãy viết "analysis" (phân tích ngắn gọn sở thích đọc) và "reason" cho ĐỦ {$bookCount} cuốn trên — bắt buộc đủ các book_id: {$bookIdsList}.

Định dạng JSON:
{"analysis":"...","recommendations":[{$exampleItems}]}
PROMPT;
    }

    /**
     * Gemini đôi khi bọc JSON trong ```json ... ``` — cắt bỏ trước khi decode.
     */
    private function extractJson(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(json)?/i', '', $text);
        $text = preg_replace('/```$/', '', $text);
        $text = trim((string) $text);

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \RuntimeException('Gemini không trả về JSON hợp lệ.');
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Không parse được JSON từ Gemini.');
        }

        return $decoded;
    }

    /**
     * @param array<int, string> $reasons
     */
    private function mergeResults(Collection $candidates, array $reasons): array
    {
        return $candidates->map(fn ($c) => [
            'book_id' => (int) $c->book_id,
            'title' => $c->title,
            'author' => $c->author,
            'category' => $c->category,
            'reason' => $reasons[(int) $c->book_id] ?? self::FALLBACK_REASON,
        ])->values()->all();
    }

    private function loadState(string $conversationId): array
    {
        if (strlen($conversationId) < 8) {
            return [];
        }

        return Cache::get(self::CACHE_KEY_PREFIX . $conversationId, []);
    }

    private function saveState(string $conversationId, array $state): void
    {
        if (strlen($conversationId) < 8) {
            return;
        }

        Cache::put(self::CACHE_KEY_PREFIX . $conversationId, $state, now()->addMinutes(self::CACHE_TTL_MINUTES));
    }

    private function clearState(string $conversationId): void
    {
        Cache::forget(self::CACHE_KEY_PREFIX . $conversationId);
    }
}
