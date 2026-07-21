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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use Exception;

class UserController extends Controller
{
    private const READER_EMAIL_OTP_EXPIRE_MINUTES = 5;

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

    /** Send an OTP before an admin creates a reader account. */
    public function requestReaderEmailOtp(Request $request)
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'unique:users,email', $this->registrationEmailRule()],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $email = $request->input('email');
        $key = 'admin_reader_email_otp:' . $email;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'message' => 'Vui lòng chờ ' . RateLimiter::availableIn($key) . ' giây trước khi gửi lại mã.',
                'error' => 'rate_limited',
            ], 429);
        }
        RateLimiter::hit($key, 600);

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        DB::table('admin_reader_email_otps')->where('email', $email)->delete();
        DB::table('admin_reader_email_otps')->insert([
            'email' => $email,
            'otp_hash' => Hash::make($otp),
            'created_at' => now(),
        ]);

        try {
            Mail::raw(
                "Xin chào,\n\nMã OTP xác thực email để tạo tài khoản độc giả của bạn là: {$otp}\n\n"
                . 'Mã có hiệu lực trong ' . self::READER_EMAIL_OTP_EXPIRE_MINUTES . " phút và chỉ dùng được một lần.\n"
                . 'Nếu bạn không yêu cầu tạo tài khoản, hãy bỏ qua email này.',
                fn ($message) => $message->to($email)->subject('Mã xác thực email - The Library')
            );
        } catch (\Throwable $e) {
            DB::table('admin_reader_email_otps')->where('email', $email)->delete();
            RateLimiter::clear($key);
            Log::error('Failed to send admin reader email OTP: ' . $e->getMessage());
            return response()->json([
                'message' => 'Không thể gửi mã xác thực đến email này. Vui lòng thử lại.',
                'error' => 'email_delivery_failed',
            ], 502);
        }

        return response()->json(['success' => true, 'message' => 'Mã xác thực đã được gửi tới email.']);
    }

    /** Verify the OTP and return a short-lived ticket for user creation. */
    public function verifyReaderEmailOtp(Request $request)
    {
        $request->validate(['email' => 'required|email', 'otp' => 'required|string|size:6']);
        $email = strtolower(trim($request->input('email')));
        $verifyKey = 'admin_reader_email_otp_verify:' . $email;
        if (RateLimiter::tooManyAttempts($verifyKey, 5)) {
            return response()->json(['message' => 'Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau.', 'error' => 'too_many_attempts'], 429);
        }

        $record = DB::table('admin_reader_email_otps')->where('email', $email)->first();
        if (!$record || Carbon::parse($record->created_at)->addMinutes(self::READER_EMAIL_OTP_EXPIRE_MINUTES)->isPast()) {
            DB::table('admin_reader_email_otps')->where('email', $email)->delete();
            RateLimiter::hit($verifyKey, 900);
            return response()->json(['message' => 'Mã xác thực không tồn tại hoặc đã hết hạn.', 'error' => 'expired'], 422);
        }
        if (!Hash::check($request->input('otp'), $record->otp_hash)) {
            RateLimiter::hit($verifyKey, 900);
            return response()->json(['message' => 'Mã xác thực không chính xác.', 'error' => 'invalid'], 422);
        }

        DB::table('admin_reader_email_otps')->where('email', $email)->delete();
        RateLimiter::clear($verifyKey);
        $ticket = bin2hex(random_bytes(32));
        Cache::put('admin_reader_email_verified:' . hash('sha256', $ticket), $email, now()->addMinutes(10));
        return response()->json(['success' => true, 'verification_token' => $ticket, 'message' => 'Email đã được xác thực.']);
    }

    /** Send a verification link before an admin creates a reader account. */
    public function requestReaderEmailVerification(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'unique:users,email', $this->registrationEmailRule()],
            'password' => 'required|string|min:6',
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:255',
            'date_of_birth' => ['nullable', 'date', $this->minimumAgeRule()],
            'gender' => 'nullable|in:male,female,other',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $email = strtolower(trim($request->input('email')));
        $key = 'admin_reader_email_verification:' . $email;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json([
                'message' => 'Vui lòng chờ ' . RateLimiter::availableIn($key) . ' giây trước khi gửi lại mã.',
                'error' => 'rate_limited',
            ], 429);
        }
        RateLimiter::hit($key, 600);

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $pendingKey = 'admin_reader_email_pending:' . $tokenHash;
        $statusKey = 'admin_reader_email_status:' . $tokenHash;
        $avatar = null;
        if ($request->hasFile('avatar')) {
            $avatar = $request->file('avatar')->store('avatars', config('filesystems.media_disk'));
        } elseif ($request->filled('avatar')) {
            $avatar = $request->input('avatar');
        }
        $pendingData = $request->only([
            'email', 'password', 'name', 'phone', 'address', 'date_of_birth', 'gender', 'status',
        ]);
        $pendingData['avatar'] = $avatar;
        Cache::put($pendingKey, Crypt::encryptString(json_encode($pendingData)), now()->addMinutes(30));
        Cache::put($statusKey, 'pending', now()->addMinutes(30));
        // Dùng host mà admin đang truy cập để email không trỏ về localhost
        // khi APP_URL trên server chưa được cấu hình đúng.
        $requestHost = $request->getSchemeAndHttpHost();
        $requestHostName = strtolower((string) parse_url($requestHost, PHP_URL_HOST));
        $configuredHost = rtrim((string) config('app.url'), '/');
        $publicBaseUrl = in_array($requestHostName, ['localhost', '127.0.0.1', '::1'], true)
            ? $configuredHost
            : $requestHost;
        $verificationUrl = rtrim($publicBaseUrl, '/') . '/api/admin-reader-email-verification/' . $token;

        try {
            Mail::html(
                '<!doctype html><html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937">'
                . '<h2>Xác thực email độc giả</h2>'
                . '<p>Vui lòng nhấn nút bên dưới để xác nhận email và hoàn tất tạo tài khoản độc giả.</p>'
                . '<p><a href="' . e($verificationUrl) . '" style="display:inline-block;padding:12px 24px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600">Xác nhận email</a></p>'
                . '<p>Thao tác này có hiệu lực trong 30 phút. Nếu bạn không yêu cầu tạo tài khoản, hãy bỏ qua email này.</p>'
                . '</body></html>',
                fn ($message) => $message->to($email)->subject('Xac thuc email tao tai khoan - The Library')
            );
        } catch (\Throwable $e) {
            Cache::forget($pendingKey);
            Cache::forget($statusKey);
            RateLimiter::clear($key);
            Log::error('Failed to send admin reader email OTP: ' . $e->getMessage());

            return response()->json([
                'message' => 'Không thể gửi liên kết xác thực đến email này. Vui lòng kiểm tra email và thử lại.',
                'error' => 'email_delivery_failed',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'verification_token' => $token,
            'message' => 'Liên kết xác thực đã được gửi tới email độc giả.',
        ]);
    }

    /** Admin polls this endpoint while the reader verifies the email link. */
    public function readerEmailVerificationStatus(Request $request)
    {
        $token = (string) $request->input('token');
        $status = Cache::get('admin_reader_email_status:' . hash('sha256', $token));
        return response()->json(['status' => $status ?: 'expired']);
    }

    /** Public endpoint opened from the email. It creates the reader after verification. */
    public function verifyReaderEmailLink(string $token)
    {
        $tokenHash = hash('sha256', $token);
        $pendingKey = 'admin_reader_email_pending:' . $tokenHash;
        $statusKey = 'admin_reader_email_status:' . $tokenHash;
        $encrypted = Cache::pull($pendingKey);

        if (!$encrypted) {
            return response('<h2>Liên kết không hợp lệ hoặc đã hết hạn.</h2>', 422)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        try {
            $data = json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
            $createRequest = Request::create('/', 'POST', $data);
            $createRequest->merge([
                'role' => 'reader',
                'email_verification_token' => $token,
            ]);
            Cache::put('admin_reader_email_verified:' . $tokenHash, $data['email'], now()->addMinutes(2));
            $result = $this->store($createRequest);
            if ($result->getStatusCode() >= 400) {
                Cache::put($statusKey, 'failed', now()->addMinutes(30));
                return response('<h2>Không thể tạo tài khoản. Vui lòng liên hệ quản trị viên.</h2>', 500)
                    ->header('Content-Type', 'text/html; charset=UTF-8');
            }
            Cache::put($statusKey, 'verified', now()->addMinutes(30));
            return response('<h2>Email đã được xác thực thành công.</h2><p>Tài khoản độc giả của bạn đã được tạo.</p>')
                ->header('Content-Type', 'text/html; charset=UTF-8');
        } catch (\Throwable $e) {
            Cache::put($statusKey, 'failed', now()->addMinutes(30));
            Log::error('Failed to create reader after email verification: ' . $e->getMessage());
            return response('<h2>Đã xảy ra lỗi khi tạo tài khoản.</h2>', 500)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $request->merge([
            'email' => strtolower(trim((string) $request->input('email'))),
        ]);

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

        $roleName = $request->input('role', 'reader');
        $verificationCacheKey = null;
        if ($roleName === 'reader') {
            $token = (string) $request->input('email_verification_token');
            $verificationCacheKey = 'admin_reader_email_verified:' . hash('sha256', $token);
            if (!$token || Cache::get($verificationCacheKey) !== strtolower(trim($request->input('email')))) {
                return response()->json(['message' => 'Vui lòng xác thực email bằng mã OTP trước khi tạo độc giả.', 'error' => 'email_not_verified'], 422);
            }
        }

        // Avatar: new file upload, hoặc (back-compat) một chuỗi URL truyền thẳng.
        // Lưu file trực tiếp (không qua Intervention Image) — giống ProfileController::updateAvatar,
        // vì máy chạy dev hiện tại không có extension GD nên driver ảnh sẽ báo lỗi 500.
        $avatarUrl = null;
        if ($request->hasFile('avatar')) {
            $request->validate([
                'avatar' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);

            $avatarUrl = $request->file('avatar')->store('avatars', config('filesystems.media_disk'));
        } elseif ($request->exists('avatar')) {
            $request->validate([
                'avatar' => ['nullable', 'string', 'max:255'],
            ]);
            $avatarUrl = $request->input('avatar');
        }

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
                'email_verified_at' => $role && $role->role_name === 'reader' ? now() : null,
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

        if ($verificationCacheKey) {
            Cache::forget($verificationCacheKey);
        }

        $this->activityLogService->userCreated(
            auth()->id() ?? 0,
            $user->user_id,
            [
                'full_name' => $user->full_name,
                'email'     => $user->email,
                'role'      => $roleName,
            ],
            $request->ip()
        );

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

            $newAvatarUrl = $request->file('avatar')->store('avatars', config('filesystems.media_disk'));
            $avatarProvided = true;
            // getRawOriginal(): avatar_url resolves to a full URL via the model accessor,
            // but cleanup below needs the bare disk key that was actually stored.
            $oldAvatarPathForCleanup = $user->getRawOriginal('avatar_url');
        } elseif ($request->exists('avatar')) {
            $request->validate([
                'avatar' => ['nullable', 'string', 'max:255'],
            ]);
            $newAvatarUrl = $request->input('avatar');
            $avatarProvided = true;
            if ($newAvatarUrl === '' || $newAvatarUrl === null) {
                $oldAvatarPathForCleanup = $user->getRawOriginal('avatar_url');
            }
        }

        // Chụp lại status và dữ liệu trước khi sửa cho audit log
        $oldStatus = (int) $user->status;
        $oldUserData = [
            'full_name' => $user->full_name,
            'email'     => $user->email,
            'phone'     => $user->phone,
            'address'   => $user->address,
        ];

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

        // Xóa file avatar cũ trên đĩa (chỉ khi là path do disk quản lý, không đụng URL ngoài).
        if ($oldAvatarPathForCleanup && !str_starts_with($oldAvatarPathForCleanup, 'http')) {
            Storage::disk(config('filesystems.media_disk'))->delete($oldAvatarPathForCleanup);
        }

        // Module 7 — Activity Log
        $statusChanged = false;
        if ($request->has('status')) {
            $lockAudit = self::buildLockAuditPayload($oldStatus, (int) $user->status);
            if ($lockAudit !== null) {
                $statusChanged = true;
                $method = $lockAudit['action'] === 'lock' ? 'userLocked' : 'userUnlocked';
                $this->activityLogService->{$method}(auth()->id() ?? 0, $user->user_id, $lockAudit['old_data'], $lockAudit['new_data'], $request->ip());
            }
        }
        if (!$statusChanged) {
            $newUserData = [
                'full_name' => $user->full_name,
                'email'     => $user->email,
                'phone'     => $user->phone,
                'address'   => $user->address,
            ];
            $this->activityLogService->userUpdated(auth()->id() ?? 0, $user->user_id, $oldUserData, $newUserData, $request->ip());
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

        $oldData = [
            'full_name' => $user->full_name,
            'email'     => $user->email,
        ];

        $user->delete();

        $this->activityLogService->userDeleted(auth()->id() ?? 0, (int) $id, $oldData, request()->ip());

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

        $this->activityLogService->userUpdated(auth()->id() ?? 0, $user->user_id, ['password' => '***'], ['password' => 'default_12345678'], $request->ip());

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $this->formatUser($user)
            ],
            'message' => 'Khôi phục mật khẩu mặc định thành công (12345678).'
        ]);
    }
}
