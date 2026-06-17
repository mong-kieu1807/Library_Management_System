<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Fine extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function borrowTransaction()
    {
        return $this->belongsTo(BorrowTransaction::class);
    }

    public function bookCopy()
    {
        return $this->belongsTo(BookCopy::class, 'copy_id');
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }
    protected $fillable = [
    'user_id',
    'borrow_id',
    'copy_id',
    'amount',
    'reason',
    'status',
    'created_at'
    ];
}
