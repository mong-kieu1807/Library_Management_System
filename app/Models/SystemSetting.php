<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'system_settings';
    protected $primaryKey = 'setting_id';

    // TiDB: setting_id không có AUTO_INCREMENT -> phải tự truyền id khi tạo mới
    // (xem migration seed Module 7 / hotfix pattern chung của dự án).
    public $incrementing = false;
    // Bảng chỉ có updated_at, không có created_at -> không dùng timestamps tự động
    // của Eloquent (mặc định cần cả 2 cột, thiếu created_at sẽ lỗi SQL khi tạo mới).
    public $timestamps = false;

    protected $fillable = [
    'setting_id',
    'config_key',
    'config_value',
    'updated_at'
];
}
