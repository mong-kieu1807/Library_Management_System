<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    public function fine()
    {
        return $this->belongsTo(Fine::class);
    }
}
