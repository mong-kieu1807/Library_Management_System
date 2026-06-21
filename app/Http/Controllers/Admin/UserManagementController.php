<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Exception;

class UserManagementController extends Controller
{
    /**
     * Map a User model to the front-end IDetailUser/IListUser structure.
     */
    private function formatUser(User $user)
    {
        $roleName = $user->role ? $user->role->role_name : 'reader';
        
        // Dynamically compute achievement based on borrow transaction counts
        $borrowCount = $user->borrowTransactions()->count();
        if ($borrowCount < 5) {
            $achievement = [
                'value' => 'new',
                'label' => 'Độc giả Mới'
            ];
        } elseif ($borrowCount <= 15) {
            $achievement = [
                'value' => 'expert',
                'label' => 'Độc giả Thân Thiết'
            ];
        } else {
            $achievement = [
                'value' => 'master',
                'label' => 'Bậc Thầy Đọc Sách'
            ];
        }

        return [
            'id' => (string)$user->user_id,
            'name' => $user->full_name,
            'email' => $user->email,
            'role' => $roleName,
            'phone' => $user->phone,
            'avatar' => $user->avatar_url,
            'address' => $user->address,
            'status' => [
                'value' => (string)$user->status,
                'label' => $user->status === 1 ? 'Active' : 'Inactive',
            ],
            'achievement' => $achievement,
            'createdAt' => $user->created_at ? $user->created_at->toIso8601String() : null,
            'updatedAt' => $user->updated_at ? $user->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * Display a listing of users with pagination and search.
     */
    public function index(Request $request)
    {
        $page = (int)$request->input('page', 1);
        $limit = (int)$request->input('limit', 10);
        $keyword = $request->input('keyword');
        $sortBy = $request->input('sort_by', 'user_id');
        $sortDirection = $request->input('sort_direction', 'DESC');

        // Map frontend sort fields to database columns
        if ($sortBy === 'id') {
            $sortBy = 'user_id';
        } elseif ($sortBy === 'name') {
            $sortBy = 'full_name';
        }

        $query = User::with('role');

        if ($keyword) {
            $query->where(function($q) use ($keyword) {
                $q->where('full_name', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('email', 'LIKE', '%' . $keyword . '%')
                  ->orWhere('phone', 'LIKE', '%' . $keyword . '%');
            });
        }

        $query->orderBy($sortBy, $sortDirection);

        $paginator = $query->paginate($limit, ['*'], 'page', $page);
        
        $formattedUsers = collect($paginator->items())->map(function($user) {
            return $this->formatUser($user);
        })->toArray();

        $totalCount = $paginator->total();
        $totalPages = $paginator->lastPage();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'total' => $totalCount,
                    'rows' => $formattedUsers
                ]
            ],
            'pagination' => [
                'total' => $totalCount,
                'totalPages' => $totalPages,
                'limit' => $limit,
                'first' => $page === 1,
                'last' => $page >= $totalPages,
                'hasNext' => $page < $totalPages,
                'hasPrevious' => $page > 1,
                'nextPage' => $page < $totalPages ? $page + 1 : null,
                'previousPage' => $page > 1 ? $page - 1 : null
            ]
        ]);
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
            'name' => 'required|string|max:150',
            'role' => 'string',
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

        $roleName = $request->input('role', 'reader');
        $role = Role::where('role_name', $roleName)->first();
        if (!$role) {
            $role = Role::where('role_name', 'reader')->first();
        }

        // Get status value if provided in object form
        $statusValue = $request->input('status.value', 1);
        if (is_array($request->input('status'))) {
            $statusValue = (int)($request->input('status.value', 1));
        }

        $user = User::create([
            'role_id' => $role ? $role->role_id : 3, // Fallback to 3 if not found
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'full_name' => $request->input('name'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'status' => $statusValue,
            'avatar_url' => $request->input('avatar'),
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatUser($user)
            ]
        ]);
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::with('role')->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy người dùng.'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatUser($user)
            ]
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy người dùng.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'email|unique:users,email,' . $id . ',user_id',
            'password' => 'nullable|string|min:6',
            'name' => 'string|max:150',
            'role' => 'string',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'avatar' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Dữ liệu cập nhật không hợp lệ.',
                'errors' => $validator->errors()
            ], 422);
        }

        // Cập nhật các trường
        if ($request->has('name')) {
            $user->full_name = $request->input('name');
        }
        if ($request->has('email')) {
            $user->email = $request->input('email');
        }
        if ($request->has('password') && $request->input('password')) {
            $user->password = Hash::make($request->input('password'));
        }
        if ($request->has('phone')) {
            $user->phone = $request->input('phone');
        }
        if ($request->has('address')) {
            $user->address = $request->input('address');
        }
        if ($request->has('avatar')) {
            $user->avatar_url = $request->input('avatar');
        }
        
        // Handle status if provided in object form or direct value
        if ($request->has('status')) {
            $statusInput = $request->input('status');
            if (is_array($statusInput)) {
                $user->status = (int)($statusInput['value'] ?? 1);
            } else {
                $user->status = (int)$statusInput;
            }
        }

        // Handle role update
        if ($request->has('role')) {
            $roleName = $request->input('role');
            $role = Role::where('role_name', $roleName)->first();
            if ($role) {
                $user->role_id = $role->role_id;
            }
        }

        $user->save();

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatUser($user)
            ]
        ]);
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy người dùng.'
            ], 404);
        }

        $user->delete();

        return response()->json([
            'code' => 200,
            'message' => 'Xóa người dùng thành công.'
        ]);
    }

    /**
     * Reset user password.
     */
    public function resetPassword(Request $request, $id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy người dùng.'
            ], 404);
        }

        $user->password = Hash::make('12345678');
        $user->save();

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatUser($user)
            ],
            'message' => 'Khôi phục mật khẩu mặc định thành công (12345678).'
        ]);
    }
}
