<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;

/**
 * Module 7 (Phase 5.5) — Email Template Management. Chỉ Admin dùng (xem route group).
 * Chỉ cho sửa subject/html_content/description — không cho đổi template_code/template_name
 * vì đó là định danh cố định của template (dùng làm khóa để hệ thống tra cứu khi gửi mail).
 */
class EmailTemplateController extends Controller
{
    public function __construct(private ActivityLogService $activityLogService)
    {
    }

    /**
     * GET /private/v1/email-templates
     */
    public function index(Request $request)
    {
        $query = EmailTemplate::query();

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('template_name', 'like', "%{$search}%")
                  ->orWhere('template_code', 'like', "%{$search}%");
            });
        }

        $query->orderBy('template_name');

        $templates = $query->paginate((int) $request->input('per_page', 20));

        return response()->json([
            'code'    => 200,
            'results' => [
                'objects'  => $templates->items(),
                'total'    => $templates->total(),
                'per_page' => $templates->perPage(),
                'page'     => $templates->currentPage(),
            ],
        ]);
    }

    /**
     * GET /private/v1/email-templates/{id}
     */
    public function show(int $id)
    {
        $template = EmailTemplate::find($id);

        if (!$template) {
            return response()->json(['message' => 'Không tìm thấy email template.'], 404);
        }

        return response()->json([
            'code'    => 200,
            'results' => ['object' => $template],
        ]);
    }

    /**
     * PUT /private/v1/email-templates/{id}
     * Chỉ nhận subject/html_content/description — template_id/template_code/template_name
     * không nằm trong $validated nên không thể lọt vào update() dù client cố gửi thêm.
     */
    public function update(Request $request, int $id)
    {
        $template = EmailTemplate::find($id);

        if (!$template) {
            return response()->json(['message' => 'Không tìm thấy email template.'], 404);
        }

        $validated = $request->validate([
            'subject'      => ['required', 'string', 'max:255'],
            'html_content' => ['required', 'string'],
            'description'  => ['nullable', 'string'],
        ], [
            'subject.required'      => 'Vui lòng nhập tiêu đề email.',
            'subject.max'           => 'Tiêu đề không được vượt quá 255 ký tự.',
            'html_content.required' => 'Vui lòng nhập nội dung email.',
        ]);

        $oldData = $template->toArray();

        $template->update([
            'subject'      => $validated['subject'],
            'html_content' => $validated['html_content'],
            'description'  => $validated['description'] ?? null,
            'updated_at'   => now(),
        ]);

        $this->activityLogService->emailTemplateUpdated(
            auth()->id(),
            $id,
            $oldData,
            $template->fresh()->toArray(),
            $request->ip()
        );

        return response()->json([
            'code'    => 200,
            'results' => ['object' => $template],
        ]);
    }
}
