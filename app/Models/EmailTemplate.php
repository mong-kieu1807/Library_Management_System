<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $primaryKey = 'template_id';
    public $incrementing = true;
    protected $fillable = [
    'template_code',
    'template_name',
    'subject',
    'html_content',
    'description',
    'updated_at'
];
}
