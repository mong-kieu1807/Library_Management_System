<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class LibrarianManagementController extends Controller
{
    /**
     * Format a Librarian user model to front-end structure.
     */
    private function formatLibrarian(User $user)
    {
        return [
            'id' => (string)$user->user_id,
            'name' => $user->full_name,
            'email' => $user->email,
            'role' => 'librarian',
            'librarian_level' => $user->librarian_level, // 'head', 'assistant', 'view_only'
            'phone' => $user->phone,
            'address' => $user->address,
            'avatar' => $user->avatar_url,
            'status' => [
                'value' => (string)$user->status,
                'label' => $user->status === 1 ? 'Active' : 'Inactive',
            ],
            'createdAt' => $user->created_at ? $user->created_at->toIso8601String() : null,
            'updatedAt' => $user->updated_at ? $user->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * Get list of all Librarians.
     */
    public function index(Request $request)
    {
        $keyword = $request->input('keyword');
        $limit = (int)$request->input('limit', 10);
        $page = (int)$request->input('page', 1);

        $query = User::whereHas('role', function ($q) {
            $q->where('role_name', 'librarian');
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
            return $this->formatLibrarian($user);
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
     * Store a new Librarian (Admin only).
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'name' => 'required|string|max:150',
            'librarian_level' => 'required|string|in:head,assistant,view_only',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dữ liệu đầu vào không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Generate temporary password
        $tempPassword = Str::random(8);

        // Fetch librarian role
        $role = Role::where('role_name', 'librarian')->first();
        $roleId = $role ? $role->role_id : 2;

        $user = User::create([
            'role_id' => $roleId,
            'email' => $request->email,
            'password' => Hash::make($tempPassword),
            'full_name' => $request->name,
            'phone' => $request->phone,
            'address' => $request->address,
            'avatar_url' => $request->avatar,
            'librarian_level' => $request->librarian_level,
            'status' => 1, // active by default
        ]);

        // Send email with temporary password
        $emailSent = false;
        $emailError = null;
        try {
            Mail::raw("Chào {$user->full_name},\n\nTài khoản thủ thư của bạn đã được tạo thành công.\nThông tin đăng nhập:\nEmail: {$user->email}\nMật khẩu tạm thời: {$tempPassword}\n\nVui lòng đăng nhập và đổi mật khẩu ngay lập tức.", function ($message) use ($user) {
                $message->to($user->email)
                        ->subject("[Library System] Thông tin tài khoản thủ thư mới");
            });
            $emailSent = true;
        } catch (\Throwable $e) {
            $emailError = $e->getMessage();
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => array_merge($this->formatLibrarian($user), [
                    'temp_password' => $tempPassword, // Return for debugging/backup in case mail fails
                    'email_sent' => $emailSent,
                    'email_error' => $emailError
                ])
            ],
            'message' => 'Tạo tài khoản thủ thư thành công.'
        ]);
    }

    /**
     * Update a Librarian.
     */
    public function update(Request $request, $id)
    {
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'librarian');
        })->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy thủ thư.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'email|unique:users,email,' . $id . ',user_id',
            'name' => 'string|max:150',
            'librarian_level' => 'string|in:head,assistant,view_only',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:255',
            'status' => 'integer|in:0,1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dữ liệu cập nhật không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->has('name')) $user->full_name = $request->name;
        if ($request->has('email')) $user->email = $request->email;
        if ($request->has('librarian_level')) $user->librarian_level = $request->librarian_level;
        if ($request->has('phone')) $user->phone = $request->phone;
        if ($request->has('address')) $user->address = $request->address;
        if ($request->has('avatar')) $user->avatar_url = $request->avatar;

        if ($request->has('status')) {
            $user->status = (int)$request->status;
        }

        $user->save();

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatLibrarian($user)
            ],
            'message' => 'Cập nhật thủ thư thành công.'
        ]);
    }

    /**
     * Reset Librarian password to a default value (e.g. 12345678).
     */
    public function resetPassword($id)
    {
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'librarian');
        })->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy thủ thư.'
            ], 404);
        }

        $user->password = Hash::make('12345678');
        $user->save();

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatLibrarian($user)
            ],
            'message' => 'Khôi phục mật khẩu mặc định thành công (12345678).'
        ]);
    }

    /**
     * Delete a Librarian.
     */
    public function destroy($id)
    {
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'librarian');
        })->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy thủ thư.'
            ], 404);
        }

        $user->delete();

        return response()->json([
            'code' => 200,
            'message' => 'Xóa tài khoản thủ thư thành công.'
        ]);
    }
}
