<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Role;
use App\Services\ActivityLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Exception;

class UserController extends Controller
{
    public function __construct(private ActivityLogService $activityLogService)
    {
    }

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

        $card = $user->libraryCard;
        $cardNumber = $card ? $card->card_number : '—';

        return [
            'id' => (string)$user->user_id,
            'name' => $user->full_name,
            'email' => $user->email,
            'role' => $roleName,
            'phone' => $user->phone,
            'avatar' => $user->avatar_url,
            'address' => $user->address,
            'date_of_birth' => $user->date_of_birth ? $user->date_of_birth->format('Y-m-d') : null,
            'gender' => $user->gender,
            'card_number' => $cardNumber,
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

        $query = User::with(['role', 'libraryCard'])->whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        });

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

    private function registrationEmailRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (!str_ends_with(strtolower(trim($value)), '@gmail.com')) {
                $fail('Vui lòng sử dụng địa chỉ Gmail (@gmail.com) để thêm độc giả.');
            }
        };
    }

    /** Độc giả phải từ đủ 6 tuổi trở lên (chưa đủ tuổi không được cấp tài khoản mượn sách). */
    private function minimumAgeRule(int $minAge = 6): \Closure
    {
        return function ($attribute, $value, $fail) use ($minAge) {
            try {
                $age = Carbon::parse($value)->age;
            } catch (\Exception) {
                return; // định dạng ngày không hợp lệ đã bị chặn bởi rule 'date' riêng
            }
            if ($age < $minAge) {
                $fail("Độc giả phải từ đủ {$minAge} tuổi trở lên.");
            }
        };
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'unique:users,email', $this->registrationEmailRule()],
            'password' => 'required|string|min:6',
            'name' => 'required|string|max:150',
            'role' => 'string',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'date_of_birth' => ['nullable', 'date', $this->minimumAgeRule()],
            'gender' => 'nullable|in:male,female,other',
        ], [
            'email.unique' => 'Độc giả này đã tồn tại (email đã được sử dụng).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        // Avatar: new file upload, hoặc (back-compat) một chuỗi URL truyền thẳng.
        // Lưu file trực tiếp (không qua Intervention Image) — giống ProfileController::updateAvatar,
        // vì máy chạy dev hiện tại không có extension GD nên driver ảnh sẽ báo lỗi 500.
        $avatarUrl = null;
        if ($request->hasFile('avatar')) {
            $request->validate([
                'avatar' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);

            $path = $request->file('avatar')->store('avatars', 'public');
            $avatarUrl = '/storage/' . $path;
        } elseif ($request->exists('avatar')) {
            $request->validate([
                'avatar' => ['nullable', 'string', 'max:255'],
            ]);
            $avatarUrl = $request->input('avatar');
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

        $user = DB::transaction(function () use ($request, $role, $statusValue, $avatarUrl) {
            $user = User::create([
                'role_id' => $role ? $role->role_id : 3, // Fallback to 3 if not found
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'full_name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'address' => $request->input('address'),
                'date_of_birth' => $request->input('date_of_birth'),
                'gender' => $request->input('gender'),
                'status' => $statusValue,
                'avatar_url' => $avatarUrl,
            ]);

            // Create library card if the created user is a reader
            $roleName = $role ? $role->role_name : 'reader';
            if ($roleName === 'reader') {
                // [HOTFIX] TiDB: card_id không auto-increment — tự sinh ID
                $nextCardId = (int) (DB::table('library_cards')->lockForUpdate()->max('card_id') ?? 0) + 1;
                \Illuminate\Support\Facades\Log::debug('[LibraryCard Hotfix - Admin] Generated card_id = ' . $nextCardId);

                $cardDefaults = DB::table('system_settings')
                    ->whereIn('config_key', ['card_regular_borrow_limit', 'card_regular_max_days'])
                    ->pluck('config_value', 'config_key');

                DB::table('library_cards')->insert([
                    'card_id'         => $nextCardId,
                    'user_id'         => $user->user_id,
                    'card_number'     => 'TV' . str_pad($user->user_id, 4, '0', STR_PAD_LEFT),
                    'issue_date'      => Carbon::today(),
                    'expiry_date'     => Carbon::today()->addYear(),
                    'borrow_limit'    => (int) ($cardDefaults['card_regular_borrow_limit'] ?? 5),
                    'max_borrow_days' => (int) ($cardDefaults['card_regular_max_days'] ?? 14),
                    'card_type'       => 'regular',
                    'status'          => 1,
                ]);
            }

            return $user;
        });

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
        $user = User::with(['role', 'libraryCard'])->whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        })->find($id);

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
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        })->find($id);

        if (!$user) {
            return response()->json([
                'message' => 'Không tìm thấy người dùng.'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['sometimes', 'email', 'unique:users,email,' . $id . ',user_id', $this->registrationEmailRule()],
            'password' => 'nullable|string|min:6',
            'name' => 'string|max:150',
            'role' => 'string',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'date_of_birth' => ['nullable', 'date', $this->minimumAgeRule()],
            'gender' => 'nullable|in:male,female,other',
        ], [
            'email.unique' => 'Email này đã được sử dụng bởi độc giả khác.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        // Avatar: file mới, xóa (chuỗi rỗng), hoặc (back-compat) một chuỗi URL truyền thẳng.
        $newAvatarUrl = null;
        $avatarProvided = false;
        $oldAvatarPathForCleanup = null;

        if ($request->hasFile('avatar')) {
            $request->validate([
                'avatar' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);

            $path = $request->file('avatar')->store('avatars', 'public');
            $newAvatarUrl = '/storage/' . $path;
            $avatarProvided = true;
            $oldAvatarPathForCleanup = $user->avatar_url;
        } elseif ($request->exists('avatar')) {
            $request->validate([
                'avatar' => ['nullable', 'string', 'max:255'],
            ]);
            $newAvatarUrl = $request->input('avatar');
            $avatarProvided = true;
            if ($newAvatarUrl === '' || $newAvatarUrl === null) {
                $oldAvatarPathForCleanup = $user->avatar_url;
            }
        }

        // Chụp lại status trước khi sửa để phát hiện khóa/mở khóa cho audit log bên dưới.
        $oldStatus = (int) $user->status;

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
        if ($avatarProvided) {
            $user->avatar_url = $newAvatarUrl ?: null;
        }
        if ($request->has('date_of_birth')) {
            $user->date_of_birth = $request->input('date_of_birth');
        }
        if ($request->has('gender')) {
            $user->gender = $request->input('gender');
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

        // Xóa file avatar cũ trên đĩa (chỉ khi lưu local qua /storage/avatars/, không đụng URL ngoài).
        if ($oldAvatarPathForCleanup && str_starts_with($oldAvatarPathForCleanup, '/storage/avatars/')) {
            Storage::disk('public')->delete(str_replace('/storage/', '', $oldAvatarPathForCleanup));
        }

        // Module 7 — Activity Log: chỉ log khi status thực sự đổi (khóa/mở khóa),
        // không log các lần sửa trường khác không đụng tới status.
        if ($request->has('status')) {
            $lockAudit = self::buildLockAuditPayload($oldStatus, (int) $user->status);
            if ($lockAudit !== null) {
                $method = $lockAudit['action'] === 'lock' ? 'userLocked' : 'userUnlocked';
                $this->activityLogService->{$method}(auth()->id(), $user->user_id, $lockAudit['old_data'], $lockAudit['new_data'], $request->ip());
            }
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatUser($user)
            ]
        ]);
    }

    /**
     * So sánh status trước/sau để suy ra khóa hay mở khóa tài khoản, cùng payload
     * before/after cho audit log. Tách riêng để test được mà không cần DB.
     * Trả về null khi status không đổi (không cần ghi log).
     *
     * @return array{action: 'lock'|'unlock', old_data: array{status:string}, new_data: array{status:string}}|null
     */
    private static function buildLockAuditPayload(int $oldStatus, int $newStatus): ?array
    {
        if ($oldStatus === $newStatus) {
            return null;
        }

        $label = fn (int $status) => $status === 1 ? 'active' : 'locked';

        return [
            'action'   => $newStatus === 0 ? 'lock' : 'unlock',
            'old_data' => ['status' => $label($oldStatus)],
            'new_data' => ['status' => $label($newStatus)],
        ];
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy($id)
    {
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        })->find($id);

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
        $user = User::whereHas('role', function ($q) {
            $q->where('role_name', 'reader');
        })->find($id);

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
