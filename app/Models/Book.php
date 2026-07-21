<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Book extends Model
{
    /**
     * cover_image is stored as either a bare media-disk path ("book-covers/x.jpg")
     * or an already-absolute http(s) URL (ISBN import from Google Books). Resolve
     * the former against the configured media disk so API responses always carry
     * a directly-loadable URL, regardless of whether that disk is local or S3/Spaces.
     */
    protected function coverImage(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => ($value && !str_starts_with($value, 'http'))
                ? Storage::disk(config('filesystems.media_disk'))->url($value)
                : $value,
        );
    }

    public function publisher()
    {
        return $this->belongsTo(Publisher::class, 'publisher_id', 'publisher_id');
    }

    public function authors()
    {
        return $this->belongsToMany(
            Author::class,
            'book_authors',
            'book_id',
            'author_id',
            'book_id',
            'author_id'
        );
    }

    public function categories()
    {
        return $this->belongsToMany(
            Category::class,
            'book_categories',
            'book_id',
            'category_id'
        );
    }

    public function bookCopies()
    {
        return $this->hasMany(BookCopy::class, 'book_id', 'book_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class, 'book_id', 'book_id');
    }

    public function bookEditHistories()
    {
        return $this->hasMany(BookEditHistory::class, 'book_id', 'book_id');
    }

    public function aiRecommendations()
    {
        return $this->hasMany(AIRecommendation::class, 'book_id', 'book_id');
    }
    protected $primaryKey = 'book_id';
    public $incrementing = true;
    protected $fillable = [
    'title',
    'isbn',
    'author_id',
    'publisher_id',
    'publish_date',
    'publish_year',
    'edition',
    'language',
    'pages',
    'dimensions',
    'cover_type',
    'description',
    'cover_image',
    'avg_rating',
    'total_reviews',
    'replacement_cost',
    'is_featured',
    'created_at',
    'updated_at'
    ];
}
