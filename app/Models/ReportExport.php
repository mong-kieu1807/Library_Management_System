<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportExport extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
    protected $primaryKey = 'export_id';
    public $incrementing = true;
    protected $fillable = [
    'exported_by',
    'report_type',
    'file_name',
    'exported_at',
    'file_format',
    'date_from',
    'date_to'
    ];
}
