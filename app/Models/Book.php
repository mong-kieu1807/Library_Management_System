<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Book extends Model
{
    public function publisher()
    {
        return $this->belongsTo(Publisher::class);
    }

    public function authors()
    {
        return $this->belongsToMany(Author::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    public function bookCopies()
    {
        return $this->hasMany(BookCopy::class);
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    public function bookEditHistories()
    {
        return $this->hasMany(BookEditHistory::class);
    }

    public function aiRecommendations()
    {
        return $this->hasMany(AIRecommendation::class);
    }
}
