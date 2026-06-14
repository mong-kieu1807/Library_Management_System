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
}