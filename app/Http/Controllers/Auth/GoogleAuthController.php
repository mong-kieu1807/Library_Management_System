<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect()
    {
        return Socialite::driver('google')->stateless()->redirect();
    }

    public function callback()
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/');
        $errorBase   = "{$frontendUrl}/auth/google/callback?error=";

        try {
            $googleUser = Socialite::driver('google')->stateless()->user();
        } catch (\Throwable $e) {
            Log::error('[GoogleAuth] Socialite user() failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'query'     => request()->query(),
            ]);
            return redirect($errorBase . 'oauth_failed');
        }

        // Case 4: Google account has no email (rare — Workspace privacy setting)
        if (!$googleUser->getEmail()) {
            return redirect($errorBase . 'email_required');
        }

        try {
            $user = DB::transaction(function () use ($googleUser) {
                // Find by google_id first, then by email
                $user = User::where('google_id', $googleUser->getId())->first()
                    ?? User::where('email', $googleUser->getEmail())->first();

                if ($user) {
                    // Link google_id and verify email for existing user
                    if (empty($user->google_id)) {
                        $user->google_id = $googleUser->getId();
                    }
                    if (empty($user->email_verified_at)) {
                        $user->email_verified_at = now();
                    }
                    $user->save();
                } else {
                    // Register new user via Google
                    $readerRole = Role::where('role_name', 'reader')->firstOrFail();

                    $user = new User();
                    $user->full_name         = $googleUser->getName() ?: $googleUser->getEmail();
                    $user->email             = $googleUser->getEmail();
                    $user->password          = Str::random(32); // auto-hashed by 'hashed' cast
                    $user->role_id           = $readerRole->role_id;
                    $user->status            = 1;
                    $user->google_id         = $googleUser->getId();
                    $user->email_verified_at = now();
                    $user->avatar_url        = $googleUser->getAvatar();
                    $user->save();

                    // [HOTFIX] TiDB: card_id không auto-increment — tự sinh ID
                    $nextCardId = (int) (DB::table('library_cards')->lockForUpdate()->max('card_id') ?? 0) + 1;
                    Log::debug('[LibraryCard Hotfix] Generated card_id = ' . $nextCardId);
                    DB::table('library_cards')->insert([
                        'card_id'         => $nextCardId,
                        'user_id'         => $user->user_id,
                        'card_number'     => 'TV' . str_pad($user->user_id, 4, '0', STR_PAD_LEFT),
                        'issue_date'      => Carbon::today(),
                        'expiry_date'     => Carbon::today()->addYear(),
                        'borrow_limit'    => 5,
                        'max_borrow_days' => 14,
                        'status'          => 1,
                    ]);
                }

                return $user;
            });
        } catch (\Throwable $e) {
            Log::error('[GoogleAuth] DB transaction failed', [
                'exception'    => get_class($e),
                'message'      => $e->getMessage(),
                'file'         => $e->getFile(),
                'line'         => $e->getLine(),
                'google_email' => isset($googleUser) ? $googleUser->getEmail() : 'unknown',
                'google_id'    => isset($googleUser) ? $googleUser->getId()    : 'unknown',
            ]);
            return redirect($errorBase . 'server_error');
        }

        if ($user->status !== 1) {
            return redirect($errorBase . 'account_locked');
        }

        $roleName = $user->role ? $user->role->role_name : null;

        // Admin must use TOTP 2FA — block Google OAuth for admins
        if ($roleName === 'admin') {
            return redirect($errorBase . 'admin_not_allowed');
        }

        $token = $user->createToken('reader-token')->plainTextToken;

        try {
            \App\Models\LoginLog::create([
                'user_id'        => $user->user_id,
                'email_attempt'  => $user->email,
                'ip_address'     => request()->ip(),
                'user_agent'     => request()->userAgent(),
                'login_status'   => 'success',
                'failure_reason' => null,
                'login_time'     => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[GoogleAuth] LoginLog create failed', [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
            ]);
        }

        return redirect("{$frontendUrl}/auth/google/callback?token={$token}&user_id={$user->user_id}");
    }
}
