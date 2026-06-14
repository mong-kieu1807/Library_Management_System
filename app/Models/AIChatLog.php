<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIChatLog extends Model
{
    public function aiChatSession()
    {
        return $this->belongsTo(AIChatSession::class, 'session_id');
    }
}