<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $primaryKey = 'setting_id';
    public $incrementing = true;
    protected $fillable = [
    'config_key',
    'config_value',
    'updated_at'
];
}
