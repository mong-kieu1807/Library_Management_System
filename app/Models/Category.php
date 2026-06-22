<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    public function books()
    {
        return $this->belongsToMany(
            Book::class,
            'book_categories',
            'category_id',
            'book_id',
            'category_id',
            'book_id'
        );
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
    protected $primaryKey = 'category_id';
    public $incrementing = true;
    protected $fillable = [
    'category_name',
    'description',
    'parent_id',
    'status',
    'created_at',
    'updated_at'
    ];

}
