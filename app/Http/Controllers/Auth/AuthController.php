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

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'full_name'             => 'required|string|max:150',
            'email'                 => 'required|email|unique:users,email',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $readerRole = Role::where('role_name', 'reader')->first();

        if (!$readerRole) {
            return response()->json([
                'message' => 'Reader role not found',
            ], 500);
        }

        $user = DB::transaction(function () use ($request, $readerRole) {
            $user = User::create([
                'full_name' => $request->full_name,
                'email'     => $request->email,
                'password'  => $request->password,
                'role_id'   => $readerRole->role_id,
                'status'    => 1,
            ]);

            DB::table('library_cards')->insert([
                'user_id'         => $user->user_id,
                'card_number'     => 'TV' . str_pad($user->user_id, 4, '0', STR_PAD_LEFT),
                'issue_date'      => Carbon::today(),
                'expiry_date'     => Carbon::today()->addYear(),
                'borrow_limit'    => 5,
                'max_borrow_days' => 14,
                'status'          => 1,
            ]);

            return $user;
        });

        $userData = [
            'id'          => (string) $user->user_id,
            'name'        => $user->full_name,
            'email'       => $user->email,
            'role'        => $readerRole->role_name,
            'phone'       => $user->phone,
            'avatar'      => $user->avatar_url,
            'status'      => [
                'value' => (string) $user->status,
                'label' => $user->status === 1 ? 'Active' : 'Inactive',
            ],
            'achievement' => null,
            'createdAt'   => $user->created_at ? $user->created_at->toIso8601String() : null,
            'updatedAt'   => $user->updated_at ? $user->updated_at->toIso8601String() : null,
        ];

        return response()->json([
            'results' => [
                'object' => [
                    'accessToken' => null,
                    'user'        => $userData,
                ]
            ]
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

        // Generate Sanctum access token
        $token = $user->createToken("{$roleName}-token")->plainTextToken;
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

        // Generate Sanctum access token for non-admins (Librarians, Readers, etc.)
        // Ensure personal_access_tokens table is present
        if (!\Illuminate\Support\Facades\Schema::hasTable('personal_access_tokens')) {
            \Illuminate\Support\Facades\Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_06_18_122439_create_personal_access_tokens_table.php',
                '--force' => true
            ]);
        }
        
        $token = $user->createToken('admin-token')->plainTextToken;

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

        // Ensure personal_access_tokens table exists
        if (!\Illuminate\Support\Facades\Schema::hasTable('personal_access_tokens')) {
            \Illuminate\Support\Facades\Artisan::call('migrate', [
                '--path' => 'database/migrations/2026_06_18_122439_create_personal_access_tokens_table.php',
                '--force' => true
            ]);
        }

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
            \App\Models\LoginLog::create([
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
}
