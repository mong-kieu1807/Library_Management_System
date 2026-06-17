<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
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

        // Check if the user is an admin
        if ($roleName !== 'admin') {
            return response()->json([
                'message' => 'Bạn không có quyền truy cập vào trang quản trị.',
            ], 403);
        }

        // Check status (if active/inactive)
        if ($user->status !== 1) {
            return response()->json([
                'message' => 'Tài khoản của bạn đã bị khoá.',
            ], 403);
        }

        // Generate Sanctum access token
        $token = $user->createToken('admin-token')->plainTextToken;

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
