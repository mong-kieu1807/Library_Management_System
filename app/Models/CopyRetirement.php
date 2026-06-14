<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CopyRetirement extends Model
{
    public function bookCopy()
    {
        return $this->belongsTo(BookCopy::class, 'copy_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'retired_by');
    }
}
