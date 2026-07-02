<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogService;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Module 7 (Phase 6A) — Backup & Restore. Chỉ backup (list/create/download/delete).
 * Restore KHÔNG làm ở phase này.
 */
class BackupController extends Controller
{
    public function __construct(
        private BackupService $backupService,
        private ActivityLogService $activityLogService
    ) {
    }

    /**
     * GET /private/v1/backups
     */
    public function index()
    {
        return response()->json([
            'code'    => 200,
            'results' => ['objects' => $this->backupService->list()],
        ]);
    }

    /**
     * POST /private/v1/backups/create
     */
    public function create(Request $request)
    {
        try {
            $backup = $this->backupService->create();
        } catch (RuntimeException $e) {
            return response()->json([
                'code'    => 500,
                'message' => 'Tạo backup thất bại: ' . $e->getMessage(),
            ], 500);
        }

        $this->activityLogService->backupCreated(auth()->id(), $backup, $request->ip());

        return response()->json([
            'code'    => 200,
            'message' => 'Tạo backup thành công.',
            'results' => ['object' => $backup],
        ], 201);
    }

    /**
     * GET /private/v1/backups/download/{filename}
     */
    public function download(string $filename)
    {
        $path = $this->backupService->resolvePath($filename);

        if ($path === null) {
            return response()->json(['message' => 'Không tìm thấy file backup.'], 404);
        }

        return response()->download($path);
    }

    /**
     * DELETE /private/v1/backups/{filename}
     */
    public function destroy(Request $request, string $filename)
    {
        $path = $this->backupService->resolvePath($filename);

        if ($path === null) {
            return response()->json(['message' => 'Không tìm thấy file backup.'], 404);
        }

        $oldData = ['filename' => $filename, 'size' => File::size($path)];

        if (!$this->backupService->delete($filename)) {
            return response()->json(['message' => 'Xóa file backup thất bại.'], 500);
        }

        $this->activityLogService->backupDeleted(auth()->id(), $oldData, $request->ip());

        return response()->json(null, 204);
    }
}
