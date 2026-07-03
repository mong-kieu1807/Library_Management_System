<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;

/**
 * Module 7 — Activity Log. Chỉ đọc (không có store/update/destroy):
 * log là bằng chứng audit, không cho sửa/xóa qua API.
 */
class AuditLogController extends Controller
{
    public function __construct(private ActivityLogService $activityLogService)
    {
    }

    /**
     * GET /private/v1/activity-logs
     */
    public function index(Request $request)
    {
        $logs = $this->activityLogService->paginate([
            'user_id'    => $request->input('user'),
            'action'     => $request->input('action'),
            'table_name' => $request->input('table'),
            'from'       => $request->input('from'),
            'to'         => $request->input('to'),
            'sort'       => $request->input('sort', 'desc'),
        ], (int) $request->input('per_page', 20));

        return response()->json([
            'code'    => 200,
            'results' => [
                'objects'  => $logs->items(),
                'total'    => $logs->total(),
                'per_page' => $logs->perPage(),
                'page'     => $logs->currentPage(),
            ],
        ]);
    }

    /**
     * GET /private/v1/activity-logs/{id}
     */
    public function show(int $id)
    {
        $log = $this->activityLogService->find($id);

        if (!$log) {
            return response()->json(['message' => 'Không tìm thấy nhật ký hoạt động.'], 404);
        }

        return response()->json([
            'code'    => 200,
            'results' => ['object' => $log],
        ]);
    }
}
