<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reservation extends Model
{
    protected $table = 'reservations';

    protected $primaryKey = 'reservation_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'book_id',
        'queue_position',
        'status',
        'notified_at',
        'expired_at',
        'created_at'
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id');
    }
<<<<<<< HEAD
}
=======
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
>>>>>>> be39a3a1d26746c730d1d32823ccff003c47e1f6
