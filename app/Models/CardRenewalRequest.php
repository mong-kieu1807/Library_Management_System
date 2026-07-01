<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardRenewalRequest extends Model
{
    protected $primaryKey = 'request_id';
    public $timestamps = false;

    protected $fillable = [
        'card_id',
        'user_id',
        'requested_expiry_date',
        'status',
        'reviewed_by',
        'review_note',
        'requested_at',
    ];
}
