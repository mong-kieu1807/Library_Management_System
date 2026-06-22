<?php
require __DIR__ . '/vendor/autoload.php';

// Instantiate Google2FA or load it manually
require __DIR__ . '/app/Helpers/Google2FA.php';

$secret = \App\Helpers\Google2FA::generateSecretKey();
$code = \App\Helpers\Google2FA::getCode($secret);
$verify = \App\Helpers\Google2FA::verifyCode($secret, $code);

echo "Secret: " . $secret . "\n";
echo "Current Code: " . $code . "\n";
echo "Verification result: " . ($verify ? "SUCCESS" : "FAILED") . "\n";

// Let's print the QR code URL
echo "QR Code URL: " . \App\Helpers\Google2FA::getQRCodeUrl('test@example.com', $secret) . "\n";
