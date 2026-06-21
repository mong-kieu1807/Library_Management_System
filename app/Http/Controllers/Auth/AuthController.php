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

        // Check password
        if (!$user || !Hash::check($credentials['password'], $user->password)) {
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
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị khoá.',
            ], 403);
        }

        // Generate Sanctum access token
        $token = $user->createToken("{$roleName}-token")->plainTextToken;

        // Format user details for frontend IDetailUser structure
        $userData = [
            'id' => (string)$user->user_id,
            'name' => $user->full_name,
            'email' => $user->email,
            'role' => $roleName,
            'phone' => $user->phone,
            'avatar' => $user->avatar_url,
            'status' => [
                'value' => (string)$user->status,
                'label' => $user->status === 1 ? 'Active' : 'Inactive',
            ],
            'achievement' => null, // Placeholder or fetch achievement details if required
            'createdAt' => $user->created_at ? $user->created_at->toIso8601String() : null,
            'updatedAt' => $user->updated_at ? $user->updated_at->toIso8601String() : null,
        ];

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
}
