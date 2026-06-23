<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
    protected $primaryKey = 'backup_id';
    public $incrementing = true;
    protected $fillable = [
    'file_name',
    'file_size',
    'backup_type',
    'operation_type',
    'created_by',
    'status',
    'created_at'
    ];
}
