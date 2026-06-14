<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BookCopy extends Model
{
    public function book()
    {
        return $this->belongsTo(Book::class);
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
}
