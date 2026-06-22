<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    protected $primaryKey = 'audit_id';
    public $incrementing = true;
    protected $fillable = [
    'actor_id',
    'action',
    'table_name',
    'record_id',
    'old_data',
    'new_data',
    'ip_address',
    'created_at'
    ];
}
