<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiBookRecommendationService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "🤖 AI Gợi Ý Sách" — chat assistant cho thủ thư. Chỉ điều phối request/response,
 * toàn bộ logic hội thoại (nhớ ngữ cảnh, query DB, gọi Gemini) nằm trong
 * AiBookRecommendationService.
 */
class AiAssistantController extends Controller
{
    public function __construct(private AiBookRecommendationService $service)
    {
    }

    /**
     * POST /private/v1/ai-assistant/chat
     */
    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:500',
            'conversation_id' => 'nullable|string|max:64',
        ]);

        $conversationId = $validated['conversation_id'] ?? (string) Str::uuid();

        return response()->json(
            $this->service->handleMessage($validated['message'], $conversationId)
        );
    }
}
