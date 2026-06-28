<?php

namespace Tests\Unit;

use App\Services\IntentParserService;
use Tests\TestCase;

/**
 * 10 test cases for IntentParserService.
 * No Gemini calls, no database — pure PHP logic only.
 */
class IntentParserServiceTest extends TestCase
{
    private IntentParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new IntentParserService();
    }

    // ── Test 1: Leadership topic ──────────────────────────────────────────────

    public function test_leadership_expands_keywords(): void
    {
        $result = $this->parser->parse('Tôi muốn học lãnh đạo');

        $this->assertContains('leadership', $result['keywords'], 'keywords phải chứa "leadership"');
        $this->assertNotNull($result['topic'], 'topic phải được detect');
    }

    // ── Test 2: Children books ────────────────────────────────────────────────

    public function test_children_books_detect_target_reader_and_keywords(): void
    {
        $result = $this->parser->parse('Sách cho trẻ em');

        $hasKw = in_array('thiếu nhi', $result['keywords']) || in_array('trẻ em', $result['keywords']);
        $this->assertTrue($hasKw, 'keywords phải chứa thiếu nhi hoặc trẻ em');
        $this->assertNotNull($result['target_reader'], 'target_reader phải được detect');
    }

    // ── Test 3: Doctor / medical reader ───────────────────────────────────────

    public function test_doctor_expands_medical_keywords_and_target_reader(): void
    {
        $result = $this->parser->parse('Tôi là bác sĩ, muốn tìm sách chuyên ngành');

        $this->assertContains('medical', $result['keywords'], 'keywords phải chứa "medical"');
        $this->assertEquals('bác sĩ', $result['target_reader']);
    }

    // ── Test 4: Python programming ────────────────────────────────────────────

    public function test_python_expands_programming_keywords(): void
    {
        $result = $this->parser->parse('Học Python');

        $this->assertContains('programming', $result['keywords'], 'keywords phải chứa "programming"');
        $this->assertContains('Python', $result['keywords']);
    }

    // ── Test 5: Machine Learning topic ───────────────────────────────────────

    public function test_machine_learning_detects_topic(): void
    {
        $result = $this->parser->parse('Machine Learning');

        $this->assertEquals('machine learning', $result['topic']);
        $this->assertContains('deep learning', $result['keywords']);
    }

    // ── Test 6: Communication soft skill ─────────────────────────────────────

    public function test_communication_expands_keywords(): void
    {
        $result = $this->parser->parse('Kỹ năng giao tiếp');

        $this->assertContains('communication', $result['keywords']);
        $this->assertContains('kỹ năng mềm', $result['keywords']);
    }

    // ── Test 7: Known book title ──────────────────────────────────────────────

    public function test_known_book_title_sets_query(): void
    {
        $result = $this->parser->parse('Đắc Nhân Tâm');

        $this->assertEquals('Đắc Nhân Tâm', $result['query'], 'query phải là tên sách đã biết');
    }

    // ── Test 8: Known author name ─────────────────────────────────────────────

    public function test_known_author_detected(): void
    {
        $result = $this->parser->parse('Nguyễn Nhật Ánh');

        $this->assertEquals('Nguyễn Nhật Ánh', $result['author']);
    }

    // ── Test 9: English language request ─────────────────────────────────────

    public function test_english_language_detection(): void
    {
        $result = $this->parser->parse('Sách tiếng Anh về kinh doanh');

        $this->assertEquals('en', $result['language'], 'language phải là "en"');
    }

    // ── Test 10: Kubernetes — topic detected, would return found=false ────────

    public function test_kubernetes_topic_and_keywords(): void
    {
        $result = $this->parser->parse('Kubernetes');

        // Parser must detect the topic and expand relevant keywords
        $this->assertNotNull($result['topic'], 'topic phải được detect cho Kubernetes');
        $this->assertContains('Kubernetes', $result['keywords']);
        $this->assertContains('DevOps', $result['keywords']);
        // query / author should remain null — it's a topic search, not a specific book
        $this->assertNull($result['query']);
        $this->assertNull($result['author']);
    }
}
