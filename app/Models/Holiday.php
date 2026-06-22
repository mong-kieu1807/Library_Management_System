<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    protected $fillable = [
    'holiday_name',
    'holiday_date',
    'is_recurring',
    'description',
    'created_by',
    'created_at'
    ];
}
