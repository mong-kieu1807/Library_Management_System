<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIChatSession extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function aiChatLogs()
    {
        return $this->hasMany(AIChatLog::class, 'session_id');
    }
    protected $primaryKey = 'session_id';
    public $incrementing = true;
    protected $fillable = [
    'user_id',
    'session_id',
    'session_name',
    'created_at'
    ];
}