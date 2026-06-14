<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookEditHistory extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
}
