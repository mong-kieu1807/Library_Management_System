<?php
// Bootstrap Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->handle($request = \Illuminate\Http\Request::capture());

use App\Models\User;
use App\Helpers\Google2FA;

$email = $argv[1] ?? '0306231409@caothang.edu.vn';
$user = User::where('email', $email)->first();

if (!$user) {
    echo "USER NOT FOUND: {$email}\n";
    exit;
}

echo "User found: {$user->full_name} ({$user->email})\n";
echo "Role ID: {$user->role_id}\n";
echo "Status: {$user->status}\n";
echo "Google 2FA Secret in DB: " . ($user->google2fa_secret ?: 'NULL/EMPTY') . "\n";

if ($user->google2fa_secret) {
    $currentOtp = Google2FA::getCode($user->google2fa_secret);
    echo "Current server-calculated OTP (Time slice " . floor(time() / 30) . "): {$currentOtp}\n";
    echo "Server Time: " . date('Y-m-d H:i:s') . " (Timestamp: " . time() . ")\n";
}
