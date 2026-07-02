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
     * Hàm ghi log tổng quát — mọi hàm bên dưới đều gọi lại hàm này.
     */
    public function log(
        int $actorId,
        string $action,
        string $tableName,
        int $recordId,
        ?array $oldData = null,
        ?array $newData = null,
        ?string $ipAddress = null
    ): AuditLog {
        // TiDB: audit_id không có AUTO_INCREMENT -> tự sinh id (cùng pattern hotfix
        // đã dùng cho library_cards/holidays/system_settings ở các phase trước).
        // Dùng Eloquent (AuditLog::lockForUpdate()->max()), không dùng DB::table().
        $nextId = (int) (AuditLog::lockForUpdate()->max('audit_id') ?? 0) + 1;

        // old_data/new_data truyền mảng PHP thô — Model đã cast 'array' nên Eloquent
        // tự json_encode khi ghi (không tự encode ở đây để tránh double-encode).
        return AuditLog::create([
            'audit_id'   => $nextId,
            'actor_id'   => $actorId,
            'action'     => $action,
            'table_name' => $tableName,
            'record_id'  => $recordId,
            'old_data'   => $oldData,
            'new_data'   => $newData,
            'ip_address' => $ipAddress,
            'created_at' => now(),
        ]);
    }

    public function bookUpdated(int $actorId, int $bookId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'book_updated', 'books', $bookId, $oldData, $newData, $ipAddress);
    }

    public function settingChanged(int $actorId, int $settingId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'setting_changed', 'system_settings', $settingId, $oldData, $newData, $ipAddress);
    }

    public function userLocked(int $actorId, int $userId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'user_locked', 'users', $userId, $oldData, $newData, $ipAddress);
    }

    public function transactionDeleted(int $actorId, int $borrowId, ?array $oldData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'transaction_deleted', 'borrow_transactions', $borrowId, $oldData, null, $ipAddress);
    }

    public function holidayCreated(int $actorId, int $holidayId, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'holiday_created', 'holidays', $holidayId, null, $newData, $ipAddress);
    }

    public function holidayUpdated(int $actorId, int $holidayId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'holiday_updated', 'holidays', $holidayId, $oldData, $newData, $ipAddress);
    }

    public function holidayDeleted(int $actorId, int $holidayId, ?array $oldData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'holiday_deleted', 'holidays', $holidayId, $oldData, null, $ipAddress);
    }

    // Action giữ nguyên chữ hoa 'UPDATE_EMAIL_TEMPLATE' theo đúng yêu cầu Phase 5.5,
    // khác quy ước snake_case thường dùng ở các hàm log khác trong file này.
    public function emailTemplateUpdated(int $actorId, int $templateId, ?array $oldData, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'UPDATE_EMAIL_TEMPLATE', 'email_templates', $templateId, $oldData, $newData, $ipAddress);
    }

    // Backup không có bảng CSDL / record_id thật (định danh bằng filename, không phải
    // số nguyên) -> dùng record_id = 0 vì cột audit_logs.record_id là NOT NULL.
    // table_name = 'backups' là tên logic (không phải bảng CSDL thật) để phân biệt
    // trong danh sách activity-logs.
    public function backupCreated(int $actorId, ?array $newData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'CREATE_BACKUP', 'backups', 0, null, $newData, $ipAddress);
    }

    public function backupDeleted(int $actorId, ?array $oldData, ?string $ipAddress = null): AuditLog
    {
        return $this->log($actorId, 'DELETE_BACKUP', 'backups', 0, $oldData, null, $ipAddress);
    }

    /**
     * GET list có phân trang + filter, dùng cho ActivityLogController::index().
     *
     * @param array{user_id?:int,action?:string,table_name?:string,from?:string,to?:string,sort?:string} $filters
     */
    public function paginate(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = AuditLog::query();

        if (!empty($filters['user_id'])) {
            $query->where('actor_id', $filters['user_id']);
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
