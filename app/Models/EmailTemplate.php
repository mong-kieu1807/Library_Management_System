<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $table = 'email_templates';
    protected $primaryKey = 'template_id';

    // TiDB: template_id không có AUTO_INCREMENT (verify SHOW COLUMNS). Phase 5.5 này
    // chỉ có index/show/update (không có create) nên chưa phát sinh bug thật, nhưng
    // để đúng schema và tránh bug nếu sau này có insert, vẫn sửa cho chuẩn.
    public $incrementing = false;
    // Bảng chỉ có updated_at, không có created_at -> không dùng timestamps tự động
    // của Eloquent (mặc định sẽ cố ghi created_at không tồn tại nếu có insert sau này).
    public $timestamps = false;

    protected $fillable = [
    'template_id',
    'template_code',
    'template_name',
    'subject',
    'html_content',
    'description',
    'updated_at'
];
}
