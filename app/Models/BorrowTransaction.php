<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BorrowTransaction extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function librarian()
    {
        return $this->belongsTo(User::class, 'librarian_id');
    }

    public function bookCopies()
    {
        return $this->belongsToMany(BookCopy::class, 'borrow_details');
    }

    public function fines()
    {
        return $this->hasMany(Fine::class);
    }
    protected $fillable = [
    'user_id',
    'librarian_id',
    'borrow_date',
    'due_date',
    'status',
    'created_at',
    'updated_at'
    ];
}
