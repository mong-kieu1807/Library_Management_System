<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookEditHistory extends Model
{
    protected $primaryKey = 'history_id';
    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class, 'edited_by', 'user_id');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id', 'book_id');
    }

    protected $fillable = [
    'book_id',
    'edited_by',
    'field_name',
    'old_value',
    'new_value',
    'edit_reason',
    'edited_at'
    ];

    protected $casts = [
        'edited_at' => 'datetime',
    ];
}
