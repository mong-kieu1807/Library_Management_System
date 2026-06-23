<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ReaderManagementController extends Controller
{
    /**
     * Format a Reader user model to front-end structure.
     */
    private function formatReader(User $user)
    {
        $card = $user->libraryCard; // relation
        $cardType = ($card && $card->borrow_limit > 5) ? 'premium' : 'regular';
        $cardNumber = $card ? $card->card_number : '—';
        
        $borrowingCount = $user->borrowTransactions()->where('status', 'active')->count();
        $overdueCount = $user->borrowTransactions()->where('status', 'overdue')->count();

        return [
            'id' => (string)$user->user_id,
            'name' => $user->full_name,
            'email' => $user->email,
            'role' => 'reader',
            'phone' => $user->phone,
            'address' => $user->address,
            'avatar' => $user->avatar_url,
            'card' => $cardType, // 'regular' or 'premium'
            'card_number' => $cardNumber,
            'borrowing' => $borrowingCount,
            'overdue' => $overdueCount,
            'status' => [
                'value' => (string)$user->status,
                'label' => $user->status === 1 ? 'Active' : 'Inactive',
            ],
            'createdAt' => $user->created_at ? $user->created_at->toIso8601String() : null,
            'updatedAt' => $user->updated_at ? $user->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * Get list of Readers with search and pagination.
     */
    public function index(Request $request)
    {
        $keyword = $request->input('keyword');
        $limit = (int)$request->input('limit', 10);
        $page = (int)$request->input('page', 1);

        $query = User::with('libraryCard')->whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        });

        if ($keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('full_name', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('email', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('phone', 'LIKE', '%' . $keyword . '%');
            });
        }

        $paginator = $query->orderBy('user_id', 'DESC')->paginate($limit, ['*'], 'page', $page);

        $formatted = collect($paginator->items())->map(function ($user) {
            return $this->formatReader($user);
        })->toArray();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'total' => $paginator->total(),
                    'rows' => $formatted
                ]
            ],
            'pagination' => [
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
                'limit' => $limit,
                'first' => $page === 1,
                'last' => $page >= $paginator->lastPage(),
                'hasNext' => $page < $paginator->lastPage(),
                'hasPrevious' => $page > 1,
            ]
        ]);
    }

    /**
     * Lock or Unlock a Reader account.
     */
    public function toggleStatus(Request $request, $id)
    {
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        })->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy độc giả.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|integer|in:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Trạng thái không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        $user->status = (int)$request->status;
        $user->save();

        $statusStr = $user->status === 1 ? 'Mở khóa' : 'Khóa';

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatReader($user)
            ],
            'message' => "{$statusStr} tài khoản độc giả thành công."
        ]);
    }

    /**
     * Reset reader password to a default value (e.g. 12345678).
     */
    public function resetPassword($id)
    {
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        })->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy độc giả.'
            ], 404);
        }

        $user->password = Hash::make('12345678');
        $user->save();

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatReader($user)
            ],
            'message' => 'Khôi phục mật khẩu mặc định thành công (12345678).'
        ]);
    }

    /**
     * View borrow history of a reader.
     */
    public function borrowHistory($id)
    {
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        })->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy độc giả.'
            ], 404);
        }

        // Retrieve borrow transactions with copies, book details, and librarian details
        $transactions = DB::table('borrow_transactions')
            ->leftJoin('users as librarians', 'borrow_transactions.librarian_id', '=', 'librarians.user_id')
            ->join('borrow_details', 'borrow_transactions.borrow_id', '=', 'borrow_details.borrow_id')
            ->join('book_copies', 'borrow_details.copy_id', '=', 'book_copies.copy_id')
            ->join('books', 'book_copies.book_id', '=', 'books.book_id')
            ->select(
                'borrow_transactions.borrow_id',
                'borrow_transactions.borrow_date',
                'borrow_transactions.due_date',
                'borrow_transactions.status as transaction_status',
                'librarians.full_name as librarian_name',
                'books.title as book_title',
                'book_copies.barcode as copy_barcode',
                'borrow_details.return_date',
                'borrow_details.condition_return',
                'borrow_details.renew_count'
            )
            ->where('borrow_transactions.user_id', $id)
            ->orderBy('borrow_transactions.borrow_id', 'DESC')
            ->get();

        // Format history response
        $history = $transactions->map(function ($row) {
            return [
                'borrow_id' => $row->borrow_id,
                'borrow_date' => $row->borrow_date,
                'due_date' => $row->due_date,
                'status' => $row->transaction_status,
                'librarian_name' => $row->librarian_name,
                'book_title' => $row->book_title,
                'copy_barcode' => $row->copy_barcode,
                'return_date' => $row->return_date,
                'condition_return' => $row->condition_return,
                'renew_count' => $row->renew_count
            ];
        });

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $history
            ],
            'message' => 'Lấy lịch sử mượn trả của độc giả thành công.'
        ]);
    }
}
