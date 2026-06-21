<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController extends Controller
{
    private const OTP_EXPIRE_MINUTES = 15;

    /** Resolve authenticated user from Bearer token (mirrors logout pattern). */
    private function resolveUser(Request $request): ?User
    {
        $bearer = $request->bearerToken();
        if (!$bearer) return null;

        $tokenModel = PersonalAccessToken::findToken($bearer);
        if (!$tokenModel) return null;

        return $tokenModel->tokenable instanceof User ? $tokenModel->tokenable : null;
    }

    public function show(int $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'Người dùng không tồn tại.'], 404);
        }

        return response()->json([
            'user_id'    => $user->user_id,
            'full_name'  => $user->full_name,
            'email'      => $user->email,
            'phone'      => $user->phone,
            'address'    => $user->address,
            'avatar_url' => $user->avatar_url,
        ]);
    }

    public function update(Request $request, int $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'Người dùng không tồn tại.'], 404);
        }

        $request->validate([
            'full_name' => 'required|string|max:150',
            'phone'     => 'nullable|string|max:20',
            'address'   => 'nullable|string|max:255',
        ]);

        $user->full_name = $request->full_name;
        $user->phone     = $request->phone;
        $user->address   = $request->address;
        $user->save();

        return response()->json(['message' => 'Cập nhật hồ sơ thành công.']);
    }

    public function updateAvatar(Request $request, int $userId)
    {
        $user = User::find($userId);

        if (!$user) {
            return response()->json(['message' => 'Người dùng không tồn tại.'], 404);
        }

        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png|max:2048',
        ]);

        $path      = $request->file('avatar')->store('avatars', 'public');
        $avatarUrl = '/storage/' . $path;

        $user->avatar_url = $avatarUrl;
        $user->save();

        return response()->json([
            'message'    => 'Cập nhật ảnh đại diện thành công.',
            'avatar_url' => $avatarUrl,
        ]);
    }

    /**
     * Step 1: verify current password → generate OTP → send email.
     * User is identified from the Sanctum token (never from request body).
     */
    public function requestChangePassword(Request $request)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->validate([
            'current_password'          => 'required|string',
            'new_password'              => 'required|string|min:8|confirmed',
            'new_password_confirmation' => 'required',
        ]);

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Mật khẩu hiện tại không đúng.',
            ], 422);
        }

        // 6-digit OTP, zero-padded (e.g. "007412")
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Replace any existing OTP for this email (one active OTP per user)
        DB::table('change_password_otps')->where('email', $user->email)->delete();
        DB::table('change_password_otps')->insert([
            'email'      => $user->email,
            'otp_hash'   => Hash::make($otp),
            'created_at' => now(),
        ]);

        Mail::raw(
            "Xin chào {$user->full_name},\n\n"
            . "Mã OTP xác nhận đổi mật khẩu của bạn: {$otp}\n\n"
            . "Mã có hiệu lực trong " . self::OTP_EXPIRE_MINUTES . " phút.\n"
            . "Không chia sẻ mã này với bất kỳ ai.\n\n"
            . "Nếu bạn không yêu cầu đổi mật khẩu, hãy bỏ qua email này.",
            function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Xác nhận đổi mật khẩu - The Library');
            }
        );

        return response()->json([
            'message' => 'Mã OTP đã được gửi đến email của bạn.',
        ]);
    }

    /**
     * Step 2: verify OTP → apply new password.
     * OTP verified with Hash::check to prevent timing attacks.
     */
    public function verifyChangePassword(Request $request)
    {
        $user = $this->resolveUser($request);
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $request->validate([
            'otp'                       => 'required|string',
            'new_password'              => 'required|string|min:8|confirmed',
            'new_password_confirmation' => 'required',
        ]);

        $record = DB::table('change_password_otps')
            ->where('email', $user->email)
            ->first();

        if (!$record) {
            return response()->json([
                'message' => 'OTP không hợp lệ hoặc đã hết hạn.',
            ], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::OTP_EXPIRE_MINUTES)->isPast()) {
            DB::table('change_password_otps')->where('email', $user->email)->delete();
            return response()->json([
                'message' => 'OTP không hợp lệ hoặc đã hết hạn.',
            ], 422);
        }

        if (!Hash::check($request->otp, $record->otp_hash)) {
            return response()->json([
                'message' => 'OTP không hợp lệ hoặc đã hết hạn.',
            ], 422);
        }

        // User model has 'password' => 'hashed' cast — auto-bcrypt on assignment
        $user->password = $request->new_password;
        $user->save();

        // OTP is single-use — delete immediately after successful verification
        DB::table('change_password_otps')->where('email', $user->email)->delete();

        return response()->json([
            'message' => 'Đổi mật khẩu thành công.',
        ]);
    }
}
