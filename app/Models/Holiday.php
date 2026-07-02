<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    public function user()
    {
        // 'created_by' là FK local, owner key mặc định lấy theo User::$primaryKey ('user_id') -> đúng.
        return $this->belongsTo(User::class, 'created_by');
    }

    protected $table = 'holidays';
    // Model trước đây thiếu $primaryKey -> Eloquent mặc định tìm cột 'id' (không tồn tại).
    protected $primaryKey = 'holiday_id';

    // TiDB: holiday_id không có AUTO_INCREMENT -> phải tự truyền id khi tạo mới
    // (xem HolidayController::store()).
    public $incrementing = false;
    // Bảng chỉ có created_at, không có updated_at -> không dùng timestamps tự động của Eloquent.
    public $timestamps = false;

    protected $fillable = [
    'holiday_id',
    'holiday_name',
    'holiday_date',
    'is_recurring',
    'description',
    'created_by',
    'created_at'
    ];
}
