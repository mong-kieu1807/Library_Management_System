<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BorrowDetail extends Model
{
    public $timestamps = false;

    protected $primaryKey = null;
    public $incrementing = false;

    protected $fillable = [
        'borrow_id',
        'copy_id',
        'return_date',
        'renew_count',
        'condition_return',
        'fine_amount',
    ];

    public function transaction()
    {
        return $this->belongsTo(BorrowTransaction::class, 'borrow_id');
    }

    public function bookCopy()
    {
        return $this->belongsTo(BookCopy::class, 'copy_id');
    }
}
