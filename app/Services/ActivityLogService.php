<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Ghi và đọc audit_logs (Module 7 — Activity Log).
 * Độc lập với mọi Controller khác: bất kỳ module nào cần ghi log hành động
 * nhạy cảm (sửa sách, đổi cấu hình, khóa tài khoản, xóa giao dịch...) đều
 * gọi qua service này thay vì tự ghi SQL.
 */
class ActivityLogService
{
    /**
     * Danh sách action chuẩn hóa (Module 7 mục 8) — mọi hàm bên dưới chỉ được
     * dùng 1 trong các giá trị này, module/table_name mới là thứ phân biệt
     * nghiệp vụ (không còn nhét tên nghiệp vụ vào action như trước).
     */
    private const ACTION_CREATE = 'CREATE';
    private const ACTION_UPDATE = 'UPDATE';
    private const ACTION_DELETE = 'DELETE';
    private const ACTION_LOCK = 'LOCK';
    private const ACTION_UNLOCK = 'UNLOCK';
    private const ACTION_BACKUP = 'BACKUP';

    /**
     * Hàm ghi log tổng quát — mọi hàm bên dưới đều gọi lại hàm này.
     */
    public function log(
        int $actorId,
        string $action,
        string $tableName,
        int $recordId,
        ?array $oldData = null,
        ?array $newData = null,
        ?string $ipAddress = null,
        ?string $module = null,
        ?string $description = null
    ): AuditLog {
        // TiDB: audit_id không có AUTO_INCREMENT -> tự sinh id (cùng pattern hotfix
        // đã dùng cho library_cards/holidays/system_settings ở các phase trước).
        // Dùng Eloquent (AuditLog::lockForUpdate()->max()), không dùng DB::table().
        $nextId = (int) (AuditLog::lockForUpdate()->max('audit_id') ?? 0) + 1;

        // old_data/new_data truyền mảng PHP thô — Model đã cast 'array' nên Eloquent
        // tự json_encode khi ghi (không tự encode ở đây để tránh double-encode).
        return AuditLog::create([
            'audit_id'    => $nextId,
            'actor_id'    => $actorId,
            'action'      => $action,
            'module'      => $module,
            'table_name'  => $tableName,
            'record_id'   => $recordId,
            'old_data'    => $oldData,
            'new_data'    => $newData,
            'description' => $description,
            'ip_address'  => $ipAddress,
            'created_at'  => now(),
        ]);
    }

    public function bookUpdated(int $actorId, int $bookId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_UPDATE, 'books', $bookId, $oldData, $newData, $ipAddress, 'BOOK', 'Cập nhật thông tin sách');
    }

    public function settingChanged(int $actorId, int $settingId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        $configKey = $newData['config_key'] ?? $oldData['config_key'] ?? null;
        $description = $configKey ? "Cập nhật cấu hình: {$configKey}" : 'Cập nhật cấu hình hệ thống';

        return $this->log($actorId, self::ACTION_UPDATE, 'system_settings', $settingId, $oldData, $newData, $ipAddress, 'SYSTEM_SETTING', $description);
    }

    public function userLocked(int $actorId, int $userId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_LOCK, 'users', $userId, $oldData, $newData, $ipAddress, 'USER', 'Khóa tài khoản');
    }

    public function userUnlocked(int $actorId, int $userId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_UNLOCK, 'users', $userId, $oldData, $newData, $ipAddress, 'USER', 'Mở khóa tài khoản');
    }

    public function transactionDeleted(int $actorId, int $borrowId, ?array $oldData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_DELETE, 'borrow_transactions', $borrowId, $oldData, null, $ipAddress, 'BORROW', 'Xóa giao dịch mượn');
    }

    public function holidayCreated(int $actorId, int $holidayId, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_CREATE, 'holidays', $holidayId, null, $newData, $ipAddress, 'HOLIDAY', 'Tạo ngày nghỉ mới');
    }

    public function holidayUpdated(int $actorId, int $holidayId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_UPDATE, 'holidays', $holidayId, $oldData, $newData, $ipAddress, 'HOLIDAY', 'Cập nhật ngày nghỉ');
    }

    public function holidayDeleted(int $actorId, int $holidayId, ?array $oldData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_DELETE, 'holidays', $holidayId, $oldData, null, $ipAddress, 'HOLIDAY', 'Xóa ngày nghỉ');
    }

    public function emailTemplateUpdated(int $actorId, int $templateId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_UPDATE, 'email_templates', $templateId, $oldData, $newData, $ipAddress, 'EMAIL_TEMPLATE', 'Cập nhật mẫu email');
    }

    // Backup không có bảng CSDL / record_id thật (định danh bằng filename, không phải
    // số nguyên) -> dùng record_id = 0 vì cột audit_logs.record_id là NOT NULL.
    // table_name = 'backups' là tên logic (không phải bảng CSDL thật) để phân biệt
    // trong danh sách activity-logs.
    public function backupCreated(int $actorId, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_BACKUP, 'backups', 0, null, $newData, $ipAddress, 'BACKUP', 'Tạo bản sao lưu');
    }

    public function backupDeleted(int $actorId, ?array $oldData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, self::ACTION_DELETE, 'backups', 0, $oldData, null, $ipAddress, 'BACKUP', 'Xóa bản sao lưu');
    }

    /**
     * GET list có phân trang + filter, dùng cho ActivityLogController::index().
     *
     * @param array{user_id?:int,keyword?:string,module?:string,action?:string,table_name?:string,from?:string,to?:string,sort?:string} $filters
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = AuditLog::query()->with(['user:user_id,full_name,email']);

        if (!empty($filters['user_id'])) {
            $query->where('actor_id', $filters['user_id']);
        }
        if (!empty($filters['keyword'])) {
            $keyword = $filters['keyword'];
            $query->whereHas('user', function ($q) use ($keyword) {
                $q->where('full_name', 'LIKE', "%{$keyword}%")
                  ->orWhere('email', 'LIKE', "%{$keyword}%");
            });
        }
        if (!empty($filters['module'])) {
            $query->where('module', $filters['module']);
        }
        if (!empty($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (!empty($filters['table_name'])) {
            $query->where('table_name', $filters['table_name']);
        }
        if (!empty($filters['from'])) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $direction = ($filters['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy('created_at', $direction);

        return $query->paginate($perPage);
    }

    public function find(int $id): ?AuditLog
    {
        return AuditLog::find($id);
    }
}
