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

    /**
     * Generate a barcode not already present in book_copies, retrying on collision.
     */
    public static function generateUniqueBarcode(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = 'BC' . now()->format('ymdHis') . random_int(100, 999);
            if (!self::where('barcode', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Không thể sinh barcode duy nhất, vui lòng thử lại.');
    }

    /**
     * Sinh $count barcode tuần tự dạng "BOOK000001", "BOOK000002"... để tạo hàng loạt
     * bản sao cùng lúc. Tách riêng khỏi generateUniqueBarcode() (không đụng hành vi cũ)
     * vì đây là định dạng khác, dùng cho luồng "tạo N bản sao khi thêm đầu sách".
     * Phải gọi bên trong DB::transaction() đang mở sẵn để lockForUpdate() chặn
     * race condition giữa 2 request tạo hàng loạt đồng thời.
     */
    public static function generateSequentialBarcodes(int $count, string $prefix = 'BOOK', int $padLength = 6): array
    {
        $maxSuffix = (int) static::where('barcode', 'like', $prefix . '%')
            ->lockForUpdate()
            ->selectRaw('MAX(CAST(SUBSTRING(barcode, ?) AS UNSIGNED)) as max_suffix', [strlen($prefix) + 1])
            ->value('max_suffix');

        $barcodes = [];
        for ($i = 1; $i <= $count; $i++) {
            $barcodes[] = $prefix . str_pad((string) ($maxSuffix + $i), $padLength, '0', STR_PAD_LEFT);
        }

        return $barcodes;
    }
}
