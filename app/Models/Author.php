<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Author extends Model
{
    public function books()
    {
        return $this->belongsToMany(
            Book::class,
            'book_authors',
            'author_id',
            'book_id',
            'author_id',
            'book_id'
        );
    }
    protected $primaryKey = 'author_id';
    public $incrementing = true;
    protected $fillable = [
    'author_name',
    'bio',
    'birth_date',
    'nationality'
    ];
}
