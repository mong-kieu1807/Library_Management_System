<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookCopy extends Model
{
    public function book()
    {
        return $this->belongsTo(Book::class, 'book_id', 'book_id');
    }

    public function borrowTransactions()
    {
        return $this->belongsToMany(BorrowTransaction::class, 'borrow_details');
    }

    public function fines()
    {
        return $this->hasMany(Fine::class);
    }

    public function copyRetirements()
    {
        return $this->hasMany(CopyRetirement::class);
    }
    protected $primaryKey = 'copy_id';
    public $incrementing = true;
    protected $fillable = [
    'book_id',
    'barcode',
    'status',
    'condition',
    'shelf_location',
    'acquisition_date',
    'created_at',
    'updated_at'
    ];
}
