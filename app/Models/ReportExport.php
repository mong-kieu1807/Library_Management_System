<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
}
