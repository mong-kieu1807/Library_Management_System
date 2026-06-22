<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibraryCard extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    protected $primaryKey = 'card_id';
    public $incrementing = true;
    protected $fillable = [
    'user_id',
    'card_number',
    'issue_date',
    'expiry_date',
    'borrow_limit',
    'max_borrow_days',
    'status'
    ];
}
