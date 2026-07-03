<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public function fine()
    {
        // Trước đây đúng chỉ nhờ trùng ngẫu nhiên với đoán mặc định của Eloquent
        // (Fine::$primaryKey lúc đó vẫn là 'id' mặc định). Sau khi Fine khai đúng
        // $primaryKey = 'fine_id', đoán mặc định sẽ đổi thành 'fine_fine_id' (sai)
        // -> phải truyền rõ khóa để không phụ thuộc việc đoán ngầm.
        return $this->belongsTo(Fine::class, 'fine_id', 'fine_id');
    }
    protected $fillable = [
    'fine_id',
    'amount',
    'method',
    'payment_date'
    ];
}
