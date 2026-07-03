<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BorrowRenewalRequest extends Model
{
    protected $primaryKey = 'request_id';
    public $timestamps = false;

    protected $fillable = [
        'borrow_id',
        'user_id',
        'status',
        'reviewed_by',
        'review_note',
        'requested_at',
    ];
}
