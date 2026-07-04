<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'notification_id';
    public $incrementing = true;
    protected $keyType = 'int';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'title',
        'content',
        'type',
        'is_read',
        'created_at',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
