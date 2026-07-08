<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class ForgotPasswordController extends Controller
{
    private const OTP_EXPIRE_MINUTES = 15;

    /**
     * Quên mật khẩu dùng chung bảng change_password_otps với Đăng ký, nhưng
     * tiền tố "fp:" tách khoá riêng để không ghi đè OTP Đăng ký (email) hay
     * OTP Đổi mật khẩu khi đã đăng nhập (cũng dùng email làm khoá).
     */
    private function otpKey(string $email): string
    {
        return 'fp:' . strtolower(trim($email));
    }

    /**
     * Step 1: kiểm tra Gmail → sinh OTP → lưu → gửi mail.
     * Cũng được dùng lại cho "Gửi lại OTP" (cùng request, không cần endpoint riêng).
     */
    public function sendOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // Cooldown/resend riêng cho Forgot Password — không đụng rate limiter của Register.
        $resendKey = 'forgot_password_resend:' . strtolower(trim($request->email));
        if (RateLimiter::tooManyAttempts($resendKey, 3)) {
            $seconds = RateLimiter::availableIn($resendKey);
            return response()->json([
                'message' => "Vui lòng chờ {$seconds} giây trước khi gửi lại.",
                'error'   => 'rate_limited',
            ], 429);
        }
        RateLimiter::hit($resendKey, 600);

        $user = User::where('email', $request->email)->first();

        // Always return 200 — không tiết lộ email có tồn tại hay không
        if (!$user) {
            return response()->json([
                'message' => 'Nếu email tồn tại, mã OTP đã được gửi.',
            ]);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $key = $this->otpKey($request->email);

        DB::table('change_password_otps')->where('email', $key)->delete();
        DB::table('change_password_otps')->insert([
            'email'      => $key,
            'otp_hash'   => Hash::make($otp),
            'created_at' => now(),
        ]);

        Mail::raw(
            "Xin chào {$user->full_name},\n\n"
            . "Mã OTP đặt lại mật khẩu của bạn: {$otp}\n\n"
            . "Mã có hiệu lực trong " . self::OTP_EXPIRE_MINUTES . " phút và chỉ dùng được một lần.\n"
            . "Không chia sẻ mã này với bất kỳ ai.\n\n"
            . "Nếu bạn không yêu cầu đặt lại mật khẩu, hãy bỏ qua email này.",
            function ($message) use ($user) {
                $message->to($user->email)
                    ->subject('Mã OTP đặt lại mật khẩu - The Library');
            }
        );

        return response()->json([
            'message' => 'Nếu email tồn tại, mã OTP đã được gửi.',
        ]);
    }

    /**
     * Step 2: xác minh OTP trước khi hiển thị form mật khẩu mới ở FE.
     * Không xoá OTP ở bước này — resetPassword() xác minh lại lần cuối trước
     * khi đổi mật khẩu rồi mới xoá, đảm bảo OTP vẫn đơn dùng thật sự.
     */
    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'otp'   => 'required|string',
        ]);

        $verifyKey = 'forgot_password_otp_verify:' . strtolower(trim($request->email));
        if (RateLimiter::tooManyAttempts($verifyKey, 5)) {
            $seconds = RateLimiter::availableIn($verifyKey);
            return response()->json([
                'message' => "Bạn đã nhập sai quá nhiều lần. Vui lòng thử lại sau {$seconds} giây.",
                'error'   => 'too_many_attempts',
            ], 429);
        }

        $key    = $this->otpKey($request->email);
        $record = DB::table('change_password_otps')->where('email', $key)->first();

        if (!$record) {
            RateLimiter::hit($verifyKey, 900);
            return response()->json(['message' => 'OTP không chính xác.', 'error' => 'invalid'], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::OTP_EXPIRE_MINUTES)->isPast()) {
            DB::table('change_password_otps')->where('email', $key)->delete();
            return response()->json(['message' => 'OTP đã hết hạn.', 'error' => 'expired'], 422);
        }

        if (!Hash::check($request->otp, $record->otp_hash)) {
            RateLimiter::hit($verifyKey, 900);
            return response()->json(['message' => 'OTP không chính xác.', 'error' => 'invalid'], 422);
        }

        RateLimiter::clear($verifyKey);

        return response()->json(['message' => 'Xác minh OTP thành công.']);
    }

    /**
     * Step 3: xác minh lại OTP lần cuối → đổi mật khẩu → xoá OTP.
     * Không tự đăng nhập — FE điều hướng về màn hình Đăng nhập sau khi thành công.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'                 => 'required|email',
            'otp'                   => 'required|string',
            'password'              => ['required', 'string', 'confirmed', new \App\Rules\StrongPassword()],
            'password_confirmation' => 'required',
        ]);

        $key    = $this->otpKey($request->email);
        $record = DB::table('change_password_otps')->where('email', $key)->first();

        if (!$record) {
            return response()->json(['message' => 'OTP không hợp lệ hoặc đã hết hạn.'], 422);
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::OTP_EXPIRE_MINUTES)->isPast()) {
            DB::table('change_password_otps')->where('email', $key)->delete();
            return response()->json(['message' => 'OTP không hợp lệ hoặc đã hết hạn.'], 422);
        }

        if (!Hash::check($request->otp, $record->otp_hash)) {
            return response()->json(['message' => 'OTP không hợp lệ hoặc đã hết hạn.'], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json(['message' => 'Tài khoản không tồn tại.'], 422);
        }

        // User::$casts['password'] = 'hashed' tự bcrypt
        $user->password = $request->password;
        $user->save();

        // OTP đơn dùng — xoá ngay sau khi đổi mật khẩu thành công.
        DB::table('change_password_otps')->where('email', $key)->delete();

        return response()->json([
            'message' => 'Đổi mật khẩu thành công. Vui lòng đăng nhập.',
        ]);
    }
}
