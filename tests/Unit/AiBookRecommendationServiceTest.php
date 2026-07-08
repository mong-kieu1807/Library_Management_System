<?php

namespace Tests\Unit;

use App\Services\AIAnalysisService;
use App\Services\AiBookRecommendationService;
use App\Services\IntentParserService;
use Illuminate\Support\Collection;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * "🤖 AI Gợi Ý Sách" chat assistant — kiểm tra phần logic thuần PHP (nhận diện
 * câu trigger, tách tên độc giả, parse JSON Gemini, merge kết quả) không đụng
 * DB. Không test handleMessage()/buildRecommendationReply() end-to-end vì cần
 * bảng users/books thật (không có migration nền — cùng giới hạn như các test
 * Module 7 khác).
 */
class AiBookRecommendationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeService(?AIAnalysisService $ai = null): AiBookRecommendationService
    {
        return new AiBookRecommendationService(
            $ai ?? Mockery::mock(AIAnalysisService::class),
            new IntentParserService()
        );
    }

    private function invokePrivate(AiBookRecommendationService $service, string $method, array $args)
    {
        $ref = new ReflectionMethod(AiBookRecommendationService::class, $method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($service, $args);
    }

    // ── extractReaderNameFromTrigger ────────────────────────────────────────

    public function test_extracts_name_with_doc_gia_keyword(): void
    {
        $service = $this->makeService();
        $name = $this->invokePrivate($service, 'extractReaderNameFromTrigger', ['Gợi ý sách cho độc giả Nguyễn Văn A']);

        $this->assertSame('Nguyễn Văn A', $name);
    }

    public function test_extracts_name_without_doc_gia_keyword(): void
    {
        $service = $this->makeService();
        $name = $this->invokePrivate($service, 'extractReaderNameFromTrigger', ['Gợi ý sách cho Nguyễn Văn A']);

        $this->assertSame('Nguyễn Văn A', $name);
    }

    public function test_extracts_name_tolerates_missing_diacritics_in_trigger_phrase(): void
    {
        $service = $this->makeService();
        $name = $this->invokePrivate($service, 'extractReaderNameFromTrigger', ['goi y sach cho doc gia Nguyen Van A']);

        $this->assertSame('Nguyen Van A', $name);
    }

    public function test_returns_null_when_message_is_not_a_recommend_request(): void
    {
        $service = $this->makeService();
        $name = $this->invokePrivate($service, 'extractReaderNameFromTrigger', ['Xin chào, hôm nay thế nào?']);

        $this->assertNull($name);
    }

    public function test_returns_null_when_no_name_follows_cho(): void
    {
        $service = $this->makeService();
        $name = $this->invokePrivate($service, 'extractReaderNameFromTrigger', ['Gợi ý sách']);

        $this->assertNull($name);
    }

    // ── mergeResults ─────────────────────────────────────────────────────────

    private function candidateRow(int $bookId, string $title, string $author, string $category): object
    {
        return (object) ['book_id' => $bookId, 'title' => $title, 'author' => $author, 'category' => $category, 'borrow_count' => 0];
    }

    public function test_merge_results_uses_gemini_reason_when_present(): void
    {
        $service = $this->makeService();
        $candidates = new Collection([$this->candidateRow(21, 'Đắc Nhân Tâm', 'Dale Carnegie', 'Kỹ năng')]);

        $result = $this->invokePrivate($service, 'mergeResults', [$candidates, [21 => 'Vì bạn thích sách kỹ năng.']]);

        $this->assertSame('Vì bạn thích sách kỹ năng.', $result[0]['reason']);
    }

    public function test_merge_results_falls_back_when_gemini_has_no_reason_for_book(): void
    {
        $service = $this->makeService();
        $candidates = new Collection([$this->candidateRow(21, 'Đắc Nhân Tâm', 'Dale Carnegie', 'Kỹ năng')]);

        $result = $this->invokePrivate($service, 'mergeResults', [$candidates, []]);

        $this->assertSame('AI hiện chưa thể tạo lời giải thích, vui lòng thử lại sau.', $result[0]['reason']);
    }

    // ── explainWithGemini ────────────────────────────────────────────────────

    public function test_explain_with_gemini_parses_analysis_and_reasons(): void
    {
        $text = '{"analysis":"Độc giả thích kỹ năng mềm.","recommendations":[{"book_id":21,"reason":"Phù hợp sở thích."}]}';
        $ai = Mockery::mock(AIAnalysisService::class);
        $ai->shouldReceive('generate')->once()->andReturn(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
        $ai->shouldReceive('parseParts')->once()->andReturn([['type' => 'text', 'text' => $text]]);

        $service = $this->makeService($ai);
        $result = $this->invokePrivate($service, 'explainWithGemini', [1, new Collection(), new Collection(), new Collection()]);

        $this->assertSame('Độc giả thích kỹ năng mềm.', $result['analysis']);
        $this->assertSame('Phù hợp sở thích.', $result['reasons'][21]);
    }

    public function test_explain_with_gemini_returns_empty_when_gemini_throws(): void
    {
        $ai = Mockery::mock(AIAnalysisService::class);
        $ai->shouldReceive('generate')->once()->andThrow(new \RuntimeException('Gemini API error'));

        $service = $this->makeService($ai);
        $result = $this->invokePrivate($service, 'explainWithGemini', [1, new Collection(), new Collection(), new Collection()]);

        $this->assertSame('', $result['analysis']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_explain_with_gemini_strips_markdown_code_fence(): void
    {
        $rawText = "```json\n{\"analysis\":\"OK\",\"recommendations\":[{\"book_id\":5,\"reason\":\"Lý do.\"}]}\n```";
        $ai = Mockery::mock(AIAnalysisService::class);
        $ai->shouldReceive('generate')->once()->andReturn(['candidates' => [['content' => ['parts' => [['text' => $rawText]]]]]]);
        $ai->shouldReceive('parseParts')->once()->andReturn([['type' => 'text', 'text' => $rawText]]);

        $service = $this->makeService($ai);
        $result = $this->invokePrivate($service, 'explainWithGemini', [1, new Collection(), new Collection(), new Collection()]);

        $this->assertSame('Lý do.', $result['reasons'][5]);
    }

    // ── buildPrompt ──────────────────────────────────────────────────────────

    public function test_build_prompt_includes_stats_history_and_candidates(): void
    {
        $service = $this->makeService();
        $stats = new Collection([(object) ['category_id' => 4, 'category_name' => 'Kỹ năng', 'total' => 12]]);
        $history = new Collection([(object) ['title' => 'Sách A', 'author' => 'Tác giả A', 'category' => 'Kỹ năng', 'borrow_date' => '2026-01-01']]);
        $candidates = new Collection([$this->candidateRow(21, 'Đắc Nhân Tâm', 'Dale Carnegie', 'Kỹ năng')]);

        $prompt = $this->invokePrivate($service, 'buildPrompt', [$stats, $history, $candidates]);

        $this->assertStringContainsString('Kỹ năng (12)', $prompt);
        $this->assertStringContainsString('Sách A', $prompt);
        $this->assertStringContainsString('book_id=21', $prompt);
        $this->assertStringContainsString('book_id: 21', $prompt);
    }

    // ── buildSystemPrompt ────────────────────────────────────────────────────

    public function test_build_system_prompt_states_hard_constraints(): void
    {
        $service = $this->makeService();

        $prompt = $this->invokePrivate($service, 'buildSystemPrompt', []);

        $this->assertStringContainsString('TUYỆT ĐỐI không tự chọn thêm', $prompt);
        $this->assertStringContainsString('TUYỆT ĐỐI không tìm kiếm sách trên Internet', $prompt);
        $this->assertStringContainsString('TUYỆT ĐỐI không bịa đặt thông tin sách', $prompt);
        $this->assertStringContainsString('không được bỏ sót cuốn nào', $prompt);
    }
}
