<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchLog extends Model
{
    protected $primaryKey = 'log_id';
    public $timestamps    = false;

    protected $fillable = [
        'user_id',
        'keyword',
        'filters',
        'result_count',
        'searched_at',
        'ip_address',
    ];

    protected $casts = [
        'result_count' => 'integer',
        'searched_at'  => 'datetime',
    ];
}
