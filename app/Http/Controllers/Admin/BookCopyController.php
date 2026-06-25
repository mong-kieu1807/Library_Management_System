<?php
 
namespace App\Http\Controllers\Admin;
 
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Models\BookCopy;
 
class BookCopyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $q = $request->input('q');
        $query = BookCopy::with('book');
 
        if (!empty($q)) {
            $query->where(function ($sub) use ($q) {
                $sub->where('barcode', 'like', "%{$q}%")
                    ->orWhere('shelf_location', 'like', "%{$q}%")
                    ->orWhere('status', 'like', "%{$q}%")
                    ->orWhereHas('book', function ($b) use ($q) {
                        $b->where('title', 'like', "%{$q}%");
                    });
            });
        }
 
        $copies = $query->paginate(15);
 
        // Map data fields to match frontend properties
        $transformed = collect($copies->items())->map(function ($copy) {
            return [
                'copy_id' => $copy->copy_id,
                'book_id' => $copy->book_id,
                'book_title' => $copy->book ? $copy->book->title : 'N/A',
                'barcode' => $copy->barcode,
                'location' => $copy->shelf_location ?: 'Chưa xếp kệ',
                'condition' => $copy->condition,
                'status' => $copy->status,
                'acquired' => $copy->acquisition_date,
                'created_at' => $copy->created_at ? $copy->created_at->toDateTimeString() : null,
                'updated_at' => $copy->updated_at ? $copy->updated_at->toDateTimeString() : null,
            ];
        });
 
        // Compute status statistics
        $stats = [
            'available' => BookCopy::where('status', 'available')->count(),
            'borrowed' => BookCopy::where('status', 'borrowed')->count(),
            'reserved' => BookCopy::where('status', 'reserved')->count(),
            'lost' => BookCopy::where('status', 'lost')->count(),
            'total' => BookCopy::count(),
        ];
 
        return response()->json([
            'data' => $transformed,
            'total' => $copies->total(),
            'current_page' => $copies->currentPage(),
            'last_page' => $copies->lastPage(),
            'per_page' => $copies->perPage(),
            'stats' => $stats
        ]);
    }
 
    /**
     * Store a newly created resource in storage.
     */
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'book_id' => 'required|exists:books,book_id',
            'barcode' => 'required|string|unique:book_copies,barcode',
            'shelf_location' => 'nullable|string',
            'condition' => 'required|string|in:new,good,old,light,heavy',
            'status' => 'required|string|in:available,borrowed,reserved,maintenance,lost,liquidated',
            'acquisition_date' => 'required|date',
        ]);
 
        $copy = BookCopy::create([
            'book_id' => $validated['book_id'],
            'barcode' => $validated['barcode'],
            'shelf_location' => $validated['shelf_location'],
            'condition' => $validated['condition'],
            'status' => $validated['status'],
            'acquisition_date' => $validated['acquisition_date'],
        ]);
 
        return response()->json([
            'message' => 'Thêm mới bản sao thành công!',
            'data' => $copy
        ], 201);
    }
 
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'barcode' => 'required|string|unique:book_copies,barcode,' . $id . ',copy_id',
            'shelf_location' => 'nullable|string',
            'condition' => 'required|string|in:new,good,old,light,heavy',
            'status' => 'required|string|in:available,borrowed,reserved,maintenance,lost,liquidated',
            'acquisition_date' => 'required|date',
        ]);
 
        $copy = BookCopy::findOrFail($id);
        $copy->update([
            'barcode' => $validated['barcode'],
            'shelf_location' => $validated['shelf_location'],
            'condition' => $validated['condition'],
            'status' => $validated['status'],
            'acquisition_date' => $validated['acquisition_date'],
        ]);
 
        return response()->json([
            'message' => 'Cập nhật bản sao thành công!',
            'data' => $copy
        ]);
    }
 
    /**
     * Remove the specified resource from storage (Liquidate copy instead of physical delete).
     */
    public function destroy(Request $request, string $id)
    {
        $copy = BookCopy::findOrFail($id);
        
        // Soft-retire the copy: update its status to liquidated
        $copy->update([
            'status' => 'liquidated'
        ]);
 
        // Record details into copy_retirements
        \App\Models\CopyRetirement::create([
            'copy_id' => $copy->copy_id,
            'reason' => $request->input('reason') ?: 'Thanh lý định kỳ / Hư hại',
            'retired_by' => auth()->id() ?: 1, // Fallback to user_id 1 (Admin)
            'retired_date' => $request->input('retired_date') ?: now()->toDateString(),
            'note' => $request->input('note') ?: ''
        ]);
 
        return response()->json([
            'message' => 'Thanh lý bản sao thành công!'
        ]);
    }

    /**
     * Render printable labels for selected copy ids.
     */
    public function printLabels(Request $request)
    {
        $idsString = $request->query('ids');
        if (empty($idsString)) {
            return response()->json(['message' => 'Vui lòng chọn các bản sao để in nhãn.'], 400);
        }

        $ids = explode(',', $idsString);
        $copies = BookCopy::with('book')->whereIn('copy_id', $ids)->get();

        if ($copies->isEmpty()) {
            return response()->json(['message' => 'Không tìm thấy bản sao nào.'], 404);
        }

        return view('admin.print_labels', compact('copies'));
    }

    /**
     * Export all book copies to a CSV file.
     */
    public function exportExcel(Request $request)
    {
        $categoryId = $request->query('category_id');
        $query = BookCopy::with('book');
        if ($categoryId) {
            $query->whereHas('book.categories', function($q) use ($categoryId) {
                $q->where('categories.category_id', $categoryId);
            });
        }
        $copies = $query->get();
  
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="danh_sach_kho_sach_' . date('Ymd_His') . '.csv"',
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0'
        ];
  
        $columns = ['ID Bản Sao', 'Đầu Sách', 'Mã ISBN', 'Barcode', 'Vị Trí Kệ', 'Tình Trạng Vật Lý', 'Trạng Thái', 'Ngày Nhập Kho'];
  
        $callback = function() use ($copies, $columns) {
            $file = fopen('php://output', 'w');
            
            // Add UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            fputcsv($file, $columns);
  
            foreach ($copies as $copy) {
                $statusMap = [
                    'available' => 'Có sẵn',
                    'borrowed' => 'Đang mượn',
                    'reserved' => 'Đặt trước',
                    'maintenance' => 'Bảo trì',
                    'lost' => 'Mất/Hỏng',
                    'liquidated' => 'Đã thanh lý'
                ];
                $conditionMap = [
                    'new' => 'Mới',
                    'good' => 'Tốt',
                    'old' => 'Cũ',
                    'light' => 'Hỏng nhẹ',
                    'heavy' => 'Hỏng nặng'
                ];
  
                fputcsv($file, [
                    $copy->copy_id,
                    $copy->book ? $copy->book->title : 'N/A',
                    $copy->book ? $copy->book->isbn : 'N/A',
                    $copy->barcode,
                    $copy->shelf_location ?: 'Chưa xếp kệ',
                    $conditionMap[$copy->condition] ?? $copy->condition,
                    $statusMap[$copy->status] ?? $copy->status,
                    $copy->acquisition_date ?: 'N/A'
                ]);
            }
            fclose($file);
        };
  
        return response()->stream($callback, 200, $headers);
    }
 
    /**
     * Render A4 portrait summary report.
     */
    public function exportPdfReport(Request $request)
    {
        $categoryId = $request->query('category_id');
        
        $query = BookCopy::query();
        if ($categoryId) {
            $query->whereHas('book.categories', function($q) use ($categoryId) {
                $q->where('categories.category_id', $categoryId);
            });
        }
        
        $total = (clone $query)->count();
        $stats = [
            'available' => (clone $query)->where('status', 'available')->count(),
            'borrowed' => (clone $query)->where('status', 'borrowed')->count(),
            'reserved' => (clone $query)->where('status', 'reserved')->count(),
            'maintenance' => (clone $query)->where('status', 'maintenance')->count(),
            'lost' => (clone $query)->where('status', 'lost')->count(),
            'liquidated' => (clone $query)->where('status', 'liquidated')->count(),
        ];
        
        $conditions = [
            'new' => (clone $query)->where('condition', 'new')->count(),
            'good' => (clone $query)->where('condition', 'good')->count(),
            'old' => (clone $query)->where('condition', 'old')->count(),
            'light' => (clone $query)->where('condition', 'light')->count(),
            'heavy' => (clone $query)->where('condition', 'heavy')->count(),
        ];
  
        // Fetch 10 most recent copies
        $recentCopies = (clone $query)->with('book')->orderBy('created_at', 'desc')->limit(10)->get();
 
        $categoryName = null;
        if ($categoryId) {
            $category = \App\Models\Category::find($categoryId);
            if ($category) {
                $categoryName = $category->category_name;
            }
        }
 
        return view('admin.inventory_report', compact('total', 'stats', 'conditions', 'recentCopies', 'categoryName'));
    }
 
    /**
     * Get inventory summary report data, optionally filtered by category.
     */
    public function summaryReport(Request $request)
    {
        $categoryId = $request->query('category_id');
 
        $query = BookCopy::query();
        if ($categoryId) {
            $query->whereHas('book.categories', function($q) use ($categoryId) {
                $q->where('categories.category_id', $categoryId);
            });
        }
 
        $totalCopies = (clone $query)->count();
        $totalDistinctBooks = BookCopy::when($categoryId, function($q) use ($categoryId) {
            $q->whereHas('book.categories', function($sub) use ($categoryId) {
                $sub->where('categories.category_id', $categoryId);
            });
        })->distinct('book_id')->count('book_id');
 
        $available = (clone $query)->where('status', 'available')->count();
        $borrowed = (clone $query)->where('status', 'borrowed')->count();
        $reserved = (clone $query)->where('status', 'reserved')->count();
        $maintenance = (clone $query)->where('status', 'maintenance')->count();
        $lost = (clone $query)->where('status', 'lost')->count();
        $liquidated = (clone $query)->where('status', 'liquidated')->count();
 
        // Condition stats
        $conditionNew = (clone $query)->where('condition', 'new')->count();
        $conditionGood = (clone $query)->where('condition', 'good')->count();
        $conditionOld = (clone $query)->where('condition', 'old')->count();
        $conditionLight = (clone $query)->where('condition', 'light')->count();
        $conditionHeavy = (clone $query)->where('condition', 'heavy')->count();
 
        return response()->json([
            'total_books' => $totalDistinctBooks,
            'total_copies' => $totalCopies,
            'available' => $available,
            'borrowed' => $borrowed,
            'reserved' => $reserved,
            'maintenance' => $maintenance,
            'lost' => $lost,
            'maintenance_or_lost' => $maintenance + $lost,
            'liquidated' => $liquidated,
            'conditions' => [
                'new' => $conditionNew,
                'good' => $conditionGood,
                'old' => $conditionOld,
                'light' => $conditionLight,
                'heavy' => $conditionHeavy
            ]
        ]);
    }

    /**
     * Import copies in bulk from a CSV file.
     */
    public function importCopies(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json(['message' => 'Vui lòng chọn file tải lên.'], 400);
        }
 
        $path = $request->file('file')->getRealPath();
        $file = fopen($path, 'r');
        
        // Skip UTF-8 BOM if present
        $bom = fread($file, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($file);
        }
        
        $header = fgetcsv($file);
        if (!$header) {
            fclose($file);
            return response()->json(['message' => 'File trống hoặc lỗi cấu trúc.'], 400);
        }
 
        $rowNum = 1;
        $successCount = 0;
        $errors = [];
        $errorRows = [];
 
        while (($row = fgetcsv($file)) !== false) {
            $rowNum++;
            if (empty($row) || count($row) < 2) continue;
 
            $barcode = trim($row[0] ?? '');
            $isbn = trim($row[1] ?? '');
            $shelfLocation = trim($row[2] ?? '');
            $condition = strtolower(trim($row[3] ?? 'good'));
            $status = strtolower(trim($row[4] ?? 'available'));
            $acquisitionDate = trim($row[5] ?? '');
 
            $rowErrors = [];
 
            if (empty($barcode)) {
                $rowErrors[] = "Barcode không được để trống";
            } elseif (BookCopy::where('barcode', $barcode)->exists()) {
                $rowErrors[] = "Barcode '{$barcode}' đã tồn tại trong hệ thống";
            }
 
            if (empty($isbn)) {
                $rowErrors[] = "Mã ISBN không được để trống";
            } else {
                $book = \App\Models\Book::where('isbn', $isbn)->first();
                if (!$book) {
                    $rowErrors[] = "Không tìm thấy đầu sách có ISBN '{$isbn}'";
                }
            }
 
            $conditionMap = [
                'mới' => 'new', 'new' => 'new',
                'tốt' => 'good', 'good' => 'good',
                'cũ' => 'old', 'old' => 'old',
                'hỏng nhẹ' => 'light', 'light' => 'light',
                'hỏng nặng' => 'heavy', 'heavy' => 'heavy'
            ];
            $mappedCondition = $conditionMap[$condition] ?? 'good';
 
            $statusMap = [
                'có sẵn' => 'available', 'available' => 'available',
                'đang mượn' => 'borrowed', 'borrowed' => 'borrowed',
                'đặt trước' => 'reserved', 'reserved' => 'reserved',
                'bảo trì' => 'maintenance', 'maintenance' => 'maintenance',
                'mất/hỏng' => 'lost', 'mất' => 'lost', 'lost' => 'lost'
            ];
            $mappedStatus = $statusMap[$status] ?? 'available';
 
            if (empty($acquisitionDate)) {
                $acquisitionDate = now()->toDateString();
            }
 
            if (count($rowErrors) > 0) {
                $errors[] = [
                    'row' => $rowNum,
                    'barcode' => $barcode ?: 'N/A',
                    'errors' => $rowErrors
                ];
                $row[] = implode('; ', $rowErrors);
                $errorRows[] = $row;
            } else {
                BookCopy::create([
                    'book_id' => $book->book_id,
                    'barcode' => $barcode,
                    'shelf_location' => $shelfLocation,
                    'condition' => $mappedCondition,
                    'status' => $mappedStatus,
                    'acquisition_date' => $acquisitionDate
                ]);
                $successCount++;
            }
        }
        fclose($file);
 
        $errorCsvBase64 = null;
        if (count($errorRows) > 0) {
            ob_start();
            $df = fopen("php://output", 'w');
            fprintf($df, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($df, array_merge($header, ['Chi tiết lỗi']));
            foreach ($errorRows as $errRow) {
                fputcsv($df, $errRow);
            }
            fclose($df);
            $errorCsvBase64 = base64_encode(ob_get_clean());
        }
 
        return response()->json([
            'message' => 'Nhập kho hoàn tất!',
            'success_count' => $successCount,
            'errors' => $errors,
            'error_csv' => $errorCsvBase64
        ]);
    }
}
