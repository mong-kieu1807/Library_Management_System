<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public function user()
    {
        // FK thật trên audit_logs là actor_id (không phải user_id mặc định của Eloquent).
        return $this->belongsTo(User::class, 'actor_id', 'user_id');
    }

    protected $table = 'audit_logs';
    protected $primaryKey = 'audit_id';
    // $keyType mặc định của Eloquent đã là 'int', khớp đúng kiểu audit_id -> không cần khai lại.

    // TiDB: audit_id không có AUTO_INCREMENT (giống card_id, holiday_id, setting_id ở
    // các phase trước) -> Eloquent không được tự chờ lastInsertId(), phải tự truyền id.
    public $incrementing = false;
    // Bảng audit_logs chỉ có created_at, không có updated_at -> tắt timestamps tự động
    // của Eloquent (mặc định sẽ cố ghi cả updated_at và lỗi "Unknown column").
    public $timestamps = false;
    protected $fillable = [
    'audit_id',
    'actor_id',
    'action',
    'table_name',
    'record_id',
    'old_data',
    'new_data',
    'ip_address',
    'created_at'
    ];

    // old_data/new_data lưu JSON trong DB (longtext) -> cast 'array' để Eloquent tự
    // json_encode khi ghi và json_decode khi đọc (ActivityLogService truyền mảng PHP thô).
    protected $casts = [
        'old_data' => 'array',
        'new_data' => 'array',
    ];
}
