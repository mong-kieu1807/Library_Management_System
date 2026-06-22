<?php
// Bootstrap Laravel
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$kernel->handle($request = \Illuminate\Http\Request::capture());

use App\Models\User;
use App\Helpers\Google2FA;

$email = '0306231409@caothang.edu.vn';
$user = User::where('email', $email)->first();

if (!$user) {
    echo "USER NOT FOUND: {$email}\n";
    exit;
}

// Update the user's secret in the database to M7AI2ERE3KL5Q5AW
$user->google2fa_secret = 'M7AI2ERE3KL5Q5AW';
$user->save();

echo "Successfully updated google2fa_secret for {$email} to M7AI2ERE3KL5Q5AW!\n";

// Generate current OTP
$currentOtp = Google2FA::getCode('M7AI2ERE3KL5Q5AW');
echo "Current OTP (Server Time: " . date('Y-m-d H:i:s') . "): {$currentOtp}\n";
