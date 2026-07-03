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
            'code'    => 201,
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

        // TOCTOU: file có thể bị xoá (request khác gọi delete()) giữa lúc resolvePath()
        // xác nhận tồn tại và lúc response()->download() thực sự đọc file — đã tái hiện
        // bằng test thật (xoá file ngay sau resolvePath), Symfony ném FileNotFoundException
        // không được Laravel tự chuyển thành JSON 404 gọn cho API.
        try {
            return response()->download($path);
        } catch (\Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException) {
            return response()->json(['message' => 'Không tìm thấy file backup.'], 404);
        }
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
        $deleted = $this->backupService->delete($filename);

        // BackupService::delete() tự resolvePath() lại lần nữa bên trong -> có khoảng hở
        // giữa 2 lần resolve (đã tái hiện bằng test thật: xoá file giữa 2 lần gọi khiến
        // delete() trả false dù file thực tế đã bị xoá bởi request khác). Chỉ coi là lỗi
        // thật khi delete() thất bại VÀ file vẫn còn tồn tại (vd: lỗi quyền/khoá file) —
        // nếu file đã không còn (do đã bị xoá) thì coi là thành công (idempotent).
        if (!$deleted && File::exists($path)) {
            return response()->json(['message' => 'Xóa file backup thất bại.'], 500);
        }

        $this->activityLogService->backupDeleted(auth()->id(), $oldData, $request->ip());

        return response()->json(null, 204);
    }
}
