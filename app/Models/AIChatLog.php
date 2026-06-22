<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIChatLog extends Model
{
    public function aiChatSession()
    {
        return $this->belongsTo(AIChatSession::class, 'session_id');
    }
    protected $primaryKey = 'chat_id';
    public $incrementing = true;
    protected $fillable = [
    'session_id',
    'user_message',
    'ai_response',
    'intent',
    'created_at'
    ];
}