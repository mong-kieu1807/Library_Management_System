<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ForgotPasswordController extends Controller
{
    private const EXPIRE_MINUTES = 15;

    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Always return 200 — không tiết lộ email có tồn tại hay không
        if (!$user) {
            return response()->json([
                'message' => 'Link đặt lại mật khẩu đã được gửi.',
            ]);
        }

        // Xóa token cũ nếu có
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        // Tạo token ngẫu nhiên, lưu hash
        $token = Str::random(64);

        DB::table('password_reset_tokens')->insert([
            'email'      => $request->email,
            'token'      => Hash::make($token),
            'created_at' => now(),
        ]);

        $resetUrl = env('FRONTEND_URL', 'http://localhost:3000')
            . '/forgot-password?token=' . $token
            . '&email=' . urlencode($request->email);

        // Gửi email (MAIL_MAILER=log → ghi vào storage/logs/laravel.log)
        Mail::raw(
            "Xin chào {$user->full_name},\n\n"
            . "Link đặt lại mật khẩu của bạn (hiệu lực " . self::EXPIRE_MINUTES . " phút):\n"
            . "{$resetUrl}\n\n"
            . "Nếu bạn không yêu cầu đặt lại mật khẩu, hãy bỏ qua email này.",
            function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Đặt lại mật khẩu - The Library');
            }
        );

        return response()->json([
            'message' => 'Link đặt lại mật khẩu đã được gửi.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'                 => 'required|string',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:8|confirmed',
            'password_confirmation' => 'required',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record) {
            return response()->json([
                'message' => 'Token không hợp lệ hoặc đã hết hạn.',
            ], 422);
        }

        // Kiểm tra hết hạn 15 phút
        if (Carbon::parse($record->created_at)->addMinutes(self::EXPIRE_MINUTES)->isPast()) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'message' => 'Token không hợp lệ hoặc đã hết hạn.',
            ], 422);
        }

        // Kiểm tra token hash
        if (!Hash::check($request->token, $record->token)) {
            return response()->json([
                'message' => 'Token không hợp lệ hoặc đã hết hạn.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Tài khoản không tồn tại.',
            ], 422);
        }

        // Cập nhật password — User::$casts['password'] = 'hashed' tự bcrypt
        $user->password = $request->password;
        $user->save();

        // Xóa token — chỉ dùng 1 lần
        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Đổi mật khẩu thành công.',
        ]);
    }
}
