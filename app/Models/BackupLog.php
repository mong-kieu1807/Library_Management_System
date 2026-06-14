<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
