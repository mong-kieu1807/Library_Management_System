<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AIRecommendation extends Model
{
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function book()
    {
        return $this->belongsTo(Book::class);
    }
    protected $primaryKey = 'recommendation_id';
    public $incrementing = true;
    protected $fillable = [
    'user_id',
    'book_id',
    'score',
    'reason',
    'is_clicked',
    'created_at'
    ];
}