<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fine extends Model
{
    public function user()
    {
        // User::$primaryKey là 'user_id' (không phải 'id' mặc định) -> nếu không truyền
        // rõ 2 khóa, Eloquent tự đoán sai thành cột 'user_user_id' (đã verify bằng Tinker).
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function borrowTransaction()
    {
        // BorrowTransaction hiện chưa khai $primaryKey (mặc định 'id', không tồn tại,
        // PK thật là 'borrow_id') -> phải truyền rõ 2 khóa để không phụ thuộc vào đó.
        return $this->belongsTo(BorrowTransaction::class, 'borrow_id', 'borrow_id');
    }

    public function bookCopy()
    {
        return $this->belongsTo(BookCopy::class, 'copy_id');
    }

    public function payment()
    {
        // Truyền rõ 2 khóa để không phụ thuộc vào $primaryKey của Fine đoán ra 'fine_id'.
        return $this->hasOne(Payment::class, 'fine_id', 'fine_id');
    }

    protected $table = 'fines';
    protected $primaryKey = 'fine_id';

    // TiDB: fine_id không có AUTO_INCREMENT -> phải tự truyền id khi tạo mới
    // (xem ReturnController::confirmReturn(), đã sửa để tự sinh id tuần tự).
    public $incrementing = false;
    // Bảng chỉ có created_at, không có updated_at -> không dùng timestamps tự động của Eloquent.
    public $timestamps = false;

    protected $fillable = [
    'fine_id',
    'user_id',
    'borrow_id',
    'copy_id',
    'amount',
    'reason',
    'status',
    'created_at'
    ];
}
