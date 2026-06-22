<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Publisher extends Model
{
    public function books()
    {
        return $this->hasMany(Book::class);
    }
    protected $primaryKey = 'publisher_id';
    public $incrementing = true;
    protected $fillable = [
    'name',
    'address',
    'phone',
    'email',
    'status',
    'created_at',
    'updated_at'
    ];
}
