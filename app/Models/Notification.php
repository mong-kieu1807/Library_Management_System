<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    protected $fillable = [
    'user_id',
    'title',
    'content',
    'type',
    'is_read',
    'created_at'
];
}
