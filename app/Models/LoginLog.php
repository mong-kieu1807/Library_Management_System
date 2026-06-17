<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoginLog extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    protected $fillable = [
    'user_id',
    'email_attempt',
    'ip_address',
    'user_agent',
    'login_status',
    'failure_reason',
    'login_time'
    ];
}
