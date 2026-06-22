<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
    protected $primaryKey = 'reservation_id';
    public $incrementing = true;
    protected $fillable = [
    'user_id',
    'book_id',
    'queue_position',
    'status',
    'notified_at',
    'expired_at',
    'created_at',
    ];
}
