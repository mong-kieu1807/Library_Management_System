<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class AuthController extends Controller
{
    private const REGISTER_OTP_EXPIRE_MINUTES = 5;

    private function registrationEmailRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            if (!str_ends_with(strtolower(trim($value)), '@gmail.com')) {
                $fail('Vui lòng sử dụng địa chỉ Gmail (@gmail.com) để đăng ký.');
            }
        };
    }

    /**
     * Step 1: validate → sinh OTP → gửi email. KHÔNG tạo User ở bước này —
     * tài khoản chỉ được tạo sau khi verifyRegistrationOtp() xác minh Gmail
     * thật sự thuộc quyền sở hữu của người đăng ký.
     *
     * Dùng chung bảng OTP nhưng mỗi nghiệp vụ có workflow độc lập.
     */
    public function register(Request $request)
    {
        $request->validate([
            'full_name'             => 'required|string|max:150',
            'email'                 => ['required', 'email', 'unique:users,email', $this->registrationEmailRule()],
            'password'              => ['required', 'string', 'confirmed', new \App\Rules\StrongPassword()],
            'password_confirmation' => 'required',
        ]);

        // Cùng key với resendVerification() (không tách riêng): đây là 1 hành động
        // "gửi OTP đăng ký" duy nhất, tách key sẽ mở lại lỗ hổng spam qua register().
        // Không đụng tới key của Change Password (ProfileController không dùng RateLimiter).
        $key = 'verify_resend:' . strtolower($request->email);
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Vui lòng chờ {$seconds} giây trước khi thử lại.",
                'error'   => 'rate_limited',
            ], 429);
        }
        RateLimiter::hit($key, 600);

        $this->sendRegistrationOtp($request->email, $request->full_name);

        return response()->json([
            'success' => true,
            'message' => 'Vui lòng kiểm tra email để lấy mã OTP xác minh tài khoản.',
        ]);
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Find user by email
        $user = User::where('email', $credentials['email'])->first();

        // 1. Check if user exists
        if (!$user) {
            $this->logLoginAttempt($credentials['email'], null, 'failed', 'Email không tồn tại.');
            return response()->json([
                'message' => 'Email hoặc mật khẩu không chính xác.',
                'errors' => [
                    'email' => ['Thông tin đăng nhập không hợp lệ.']
                ]
            ], 422);
        }

        $passwordPassed = false;

        // Try checking using standard Bcrypt
        try {
            if (Hash::check($credentials['password'], $user->password)) {
                $passwordPassed = true;
            }
        } catch (\Throwable $e) {
            // Hash::check throws an exception if the stored password is not a valid Bcrypt hash
        }

        // If bcrypt check failed or database contains plain text, check plain text comparison
        if (!$passwordPassed) {
            if ($credentials['password'] === $user->password) {
                $passwordPassed = true;
                // Auto-upgrade (rehash) the plain text password in database to Bcrypt
                $user->password = Hash::make($credentials['password']);
                $user->save();
            }
        }

        if (!$passwordPassed) {
            $this->logLoginAttempt($credentials['email'], $user->user_id, 'failed', 'Mật khẩu không chính xác.');
            return response()->json([
                'message' => 'Email hoặc mật khẩu không chính xác.',
                'errors' => [
                    'email' => ['Thông tin đăng nhập không hợp lệ.']
                ]
            ], 422);
        }

        // Load the role relation
        $roleName = $user->role ? $user->role->role_name : null;

        // Only admin and reader roles are permitted
        if (!in_array($roleName, ['admin', 'reader', 'librarian'])) {
            return response()->json([
                'message' => 'Bạn không có quyền truy cập.',
            ], 403);
        }

        // Check status (if active/inactive)
        if ($user->status !== 1) {
            $this->logLoginAttempt($credentials['email'], $user->user_id, 'failed', 'Tài khoản đã bị khoá.');
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị khoá.',
            ], 403);
        }

        // Block login if email has not been verified
        if (!$user->email_verified_at) {
            return response()->json([
                'message' => 'Vui lòng xác minh email trước khi đăng nhập.',
                'error'   => 'email_not_verified',
            ], 403);
        }

        // Check if user is an admin - Admin MUST use 2FA (only if the 'google2fa_secret' column exists in the database)
        if ($roleName === 'admin' && \Illuminate\Support\Facades\Schema::hasColumn('users', 'google2fa_secret')) {
            $isSetup = empty($user->google2fa_secret);

            if ($isSetup) {
                // Generate secret if not exist yet
                $secret = \App\Helpers\Google2FA::generateSecretKey();
                $user->google2fa_secret = $secret;
                $user->save();
            } else {
                $secret = $user->google2fa_secret;
            }

            // Create temporary token with 2fa:verify ability
            $tempToken = $user->createToken('admin-temp-token', ['2fa:verify'])->plainTextToken;

            // Log attempt as successful password check but pending 2FA
            $this->logLoginAttempt($credentials['email'], $user->user_id, 'failed', 'Yêu cầu xác thực 2FA.');

            return response()->json([
                'results' => [
                    'object' => [
                        'requires_2fa' => true,
                        'is_setup' => $isSetup,
                        'secret' => $secret,
                        'qr_code_url' => \App\Helpers\Google2FA::getQRCodeUrl($user->email, $secret),
                        'tempToken' => $tempToken
                    ]
                ]
            ]);
        }

        $token = $user->createToken("{$roleName}-token")->plainTextToken;

        // Log successful login
        $this->logLoginAttempt($credentials['email'], $user->user_id, 'success');

        // Format user details for frontend
        $userData = $this->formatUserData($user, $roleName);

        // Wrap response in results.object structure expected by front-end
        return response()->json([
            'results' => [
                'object' => [
                    'accessToken' => $token,
                    'user' => $userData,
                ]
            ]
        ]);
    }

    public function verify2fa(Request $request)
    {
        $request->validate([
            'code' => 'required|string|size:6'
        ]);

        $user = $request->user();

        // Ensure token has 2fa:verify ability
        if (!$user->tokenCan('2fa:verify')) {
            return response()->json(['message' => 'Yêu cầu không hợp lệ hoặc Token đã hết hạn.'], 403);
        }

        // Verify code
        $isValid = \App\Helpers\Google2FA::verifyCode($user->google2fa_secret, $request->code);

        if (!$isValid) {
            $this->logLoginAttempt($user->email, $user->user_id, 'failed', 'Mã OTP 2FA không chính xác.');
            return response()->json([
                'message' => 'Mã OTP không chính xác.'
            ], 422);
        }

        // Delete temporary token
        $user->currentAccessToken()->delete();

        // Generate final full-access token
        $token = $user->createToken('admin-token')->plainTextToken;

        // Log successful login after 2FA
        $this->logLoginAttempt($user->email, $user->user_id, 'success');

        $roleName = $user->role ? $user->role->role_name : null;
        $userData = $this->formatUserData($user, $roleName);

        return response()->json([
            'results' => [
                'object' => [
                    'accessToken' => $token,
                    'user' => $userData,
                ]
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $bearerToken = $request->bearerToken();
        if ($bearerToken) {
            $tokenModel = \Laravel\Sanctum\PersonalAccessToken::findToken($bearerToken);
            if ($tokenModel) {
                $tokenModel->delete();
            }
        }

        return response()->json([
            'results' => [
                'object' => true
            ],
            'message' => 'Đăng xuất thành công.'
        ]);
    }

    /**
     * Format user data structure.
     */
    private function formatUserData(User $user, $roleName)
    {
        return [
            'id' => (string)$user->user_id,
            'name' => $user->full_name,
            'email' => $user->email,
            'role' => $roleName,
            'librarian_level' => $user->librarian_level,
            'phone' => $user->phone,
            'avatar' => $user->avatar_url,
            'status' => [
                'value' => (string)$user->status,
                'label' => $user->status === 1 ? 'Active' : 'Inactive',
            ],
            'achievement' => null,
            'createdAt' => $user->created_at ? $user->created_at->toIso8601String() : null,
            'updatedAt' => $user->updated_at ? $user->updated_at->toIso8601String() : null,
        ];
    }

    /**
     * Write access log.
     */
    private function logLoginAttempt($email, $userId, $status, $reason = null)
    {
        try {
            // [HOTFIX] TiDB: login_id không auto-increment — tự sinh ID (giống hotfix card_id ở trên)
            $nextLoginId = (int) (DB::table('login_logs')->lockForUpdate()->max('login_id') ?? 0) + 1;

            DB::table('login_logs')->insert([
                'login_id' => $nextLoginId,
                'user_id' => $userId,
                'email_attempt' => $email,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'login_status' => $status,
                'failure_reason' => $reason,
                'login_time' => now()
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to log login attempt: " . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────
    // Email verification
    // ──────────────────────────────────────────────────────────────

    private function generateVerifyToken(string $email): string
    {
        $payload = base64_encode(json_encode([
            'email'      => $email,
            'expires_at' => now()->addHours(24)->timestamp,
        ]));
        $sig = hash_hmac('sha256', $payload, config('app.key'));
        return $payload . '.' . $sig;
    }

    /**
     * Dùng chung bảng OTP nhưng mỗi nghiệp vụ có workflow độc lập.
     * (Register: chưa có User → không thể trùng key với Change Password,
     * vốn luôn yêu cầu User đã đăng nhập.)
     *
     * SMTP send bị hoãn tới app()->terminating() (chạy sau khi response đã trả
     * về client) để tránh việc Gmail SMTP chậm làm request vượt timeout phía FE.
     */
    private function sendRegistrationOtp(string $email, ?string $fullName = null): void
    {
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('change_password_otps')->where('email', $email)->delete();
        DB::table('change_password_otps')->insert([
            'email'      => $email,
            'otp_hash'   => Hash::make($otp),
            'created_at' => now(),
        ]);

        $greeting = $fullName ? "Xin chào {$fullName}," : 'Xin chào,';

        app()->terminating(function () use ($email, $greeting, $otp) {
            try {
                Mail::raw(
                    "{$greeting}\n\n"
                    . "Mã OTP xác minh tài khoản của bạn: {$otp}\n\n"
                    . "Mã có hiệu lực trong " . self::REGISTER_OTP_EXPIRE_MINUTES . " phút và chỉ dùng được một lần.\n"
                    . "Không chia sẻ mã này với bất kỳ ai.\n\n"
                    . "Nếu bạn không đăng ký tài khoản này, hãy bỏ qua email này.",
                    function ($message) use ($email) {
                        $message->to($email)
                            ->subject('Mã xác minh đăng ký - The Library');
                    }
                );
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send registration OTP email: ' . $e->getMessage());
            }
        });
    }

    public function verifyEmail(Request $request)
    {
        $token = $request->query('token');
        if (!$token) {
            return response()->json(['message' => 'Token không hợp lệ.', 'error' => 'invalid'], 422);
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return response()->json(['message' => 'Token không hợp lệ.', 'error' => 'invalid'], 422);
        }

        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, config('app.key'));
        if (!hash_equals($expected, $sig)) {
            return response()->json(['message' => 'Token không hợp lệ.', 'error' => 'invalid'], 422);
        }

        $data = json_decode(base64_decode($payload), true);
        if (!$data || empty($data['email']) || empty($data['expires_at'])) {
            return response()->json(['message' => 'Token không hợp lệ.', 'error' => 'invalid'], 422);
        }

        if ($data['expires_at'] < now()->timestamp) {
            return response()->json([
                'message' => 'Link xác minh đã hết hạn.',
                'error'   => 'expired',
                'email'   => $data['email'],
            ], 422);
        }

        $user = User::where('email', $data['email'])->first();
        if (!$user) {
            return response()->json(['message' => 'Tài khoản không tồn tại.', 'error' => 'not_found'], 404);
        }

        $roleName = $user->role ? $user->role->role_name : 'reader';

        if ($user->email_verified_at) {
            $autoToken = $user->createToken("{$roleName}-token")->plainTextToken;
            return response()->json([
                'message' => 'Email đã được xác minh trước đó.',
                'error'   => 'already_verified',
                'token'   => $autoToken,
                'user_id' => $user->user_id,
                'role'    => $roleName,
            ]);
        }

        $user->email_verified_at = now();
        $user->save();

        $autoToken = $user->createToken("{$roleName}-token")->plainTextToken;
        return response()->json([
            'message' => 'Email đã được xác minh thành công.',
            'success' => true,
            'token'   => $autoToken,
            'user_id' => $user->user_id,
            'role'    => $roleName,
        ]);
    }

    /**
     * Dùng chung bảng OTP nhưng mỗi nghiệp vụ có workflow độc lập.
     *
     * Verify OTP (change_password_otps). Nếu email chưa có User (đường thường —
     * đăng ký mới) thì tạo User + library card ngay bây giờ, chỉ sau khi đã
     * chứng minh sở hữu Gmail. Nếu User đã tồn tại nhưng chưa verified (tài
     * khoản cũ từ trước cơ chế OTP, hoặc vừa được Google Login tạo song song),
     * chỉ đánh dấu verified, không tạo trùng.
     */
    public function verifyRegistrationOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string',
        ]);

        $email = strtolower(trim($request->email));

        // Brute-force guard on wrong OTP guesses (mirrors resend's RateLimiter pattern).
        $verifyKey = 'register_otp_verify:' . $email;
        if (RateLimiter::tooManyAttempts($verifyKey, 5)) {
            $seconds = RateLimiter::availableIn($verifyKey);
            return response()->json([
                'message' => "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$seconds} giây.",
                'error'   => 'too_many_attempts',
            ], 429);
        }

        $record = DB::table('change_password_otps')->where('email', $request->email)->first();

        if (!$record) {
            RateLimiter::hit($verifyKey, 900);
            return response()->json(['message' => 'OTP không chính xác.', 'error' => 'invalid'], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::REGISTER_OTP_EXPIRE_MINUTES)->isPast()) {
            DB::table('change_password_otps')->where('email', $request->email)->delete();
            return response()->json(['message' => 'OTP đã hết hạn.', 'error' => 'expired'], 422);
        }

        if (!Hash::check($request->otp, $record->otp_hash)) {
            RateLimiter::hit($verifyKey, 900);
            return response()->json(['message' => 'OTP không chính xác.', 'error' => 'invalid'], 422);
        }

        RateLimiter::clear($verifyKey);
        // Single-use — xoá ngay sau khi xác minh đúng (giống hệt verifyChangePassword()).
        DB::table('change_password_otps')->where('email', $request->email)->delete();

        $user = User::where('email', $request->email)->first();

        if ($user) {
            // Tài khoản đã tồn tại từ trước (vd. tạo trước khi có cơ chế OTP này) — chỉ đánh dấu verified.
            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
                $user->save();
            }
        } else {
            // Đường thường: chỉ tạo User sau khi đã xác minh Gmail thật sự thuộc về người đăng ký.
            $request->validate([
                'full_name'             => 'required|string|max:150',
                'password'              => ['required', 'string', 'confirmed', new \App\Rules\StrongPassword()],
                'password_confirmation' => 'required',
            ]);

            $readerRole = Role::where('role_name', 'reader')->first();
            if (!$readerRole) {
                return response()->json(['message' => 'Reader role not found'], 500);
            }

            $user = DB::transaction(function () use ($request, $readerRole) {
                $user = User::create([
                    'full_name' => $request->full_name,
                    'email'     => $request->email,
                    'password'  => $request->password,
                    'role_id'   => $readerRole->role_id,
                    'status'    => 1,
                ]);

                // [HOTFIX] TiDB: card_id không auto-increment — tự sinh ID
                $nextCardId = (int) (DB::table('library_cards')->lockForUpdate()->max('card_id') ?? 0) + 1;

                // Module 7: hạn mức thẻ Thường lấy từ system_settings thay vì hard-code,
                // để Admin cấu hình được qua Cài đặt hệ thống (card_regular_borrow_limit / card_regular_max_days).
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

                return $user;
            });

            $user->email_verified_at = now();
            $user->save();
        }

        $roleName = $user->role ? $user->role->role_name : 'reader';
        $autoToken = $user->createToken("{$roleName}-token")->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Xác minh email thành công.',
            'token'   => $autoToken,
            'user_id' => $user->user_id,
            'role'    => $roleName,
        ]);
    }

    public function resendVerification(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $key = 'verify_resend:' . strtolower($request->email);
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Vui lòng chờ {$seconds} giây trước khi gửi lại.",
                'error'   => 'rate_limited',
            ], 429);
        }
        RateLimiter::hit($key, 600);

        $user = User::where('email', $request->email)->first();

        if ($user && $user->email_verified_at) {
            // Don't reveal if email is already verified
            return response()->json(['message' => 'Nếu email tồn tại và chưa xác minh, mã OTP sẽ được gửi.']);
        }

        $this->sendRegistrationOtp($request->email, $user ? $user->full_name : null);

        return response()->json(['message' => 'Nếu email tồn tại và chưa xác minh, mã OTP sẽ được gửi.']);
    }
}
