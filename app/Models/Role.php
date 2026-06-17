<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    public function users()
    {
        return $this->hasMany(User::class);
    }
    protected $primaryKey = 'role_id';
    public $incrementing = true;
    protected $fillable = [
    'role_name',
    'description'
    ];
}
