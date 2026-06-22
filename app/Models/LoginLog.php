<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
    protected $table = 'login_logs';
    protected $primaryKey = 'login_id';
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'email_attempt',
        'ip_address',
        'user_agent',
        'login_status',
        'failure_reason',
        'login_time',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
