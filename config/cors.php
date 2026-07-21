<?php

// Auth is Bearer-token based (Sanctum tokens sent via Authorization header, not
// cookies), so supports_credentials stays false and a plain origin allow-list is
// enough. CORS_ALLOWED_ORIGINS is a comma-separated list, e.g.:
//   https://admin.example.com,https://www.example.com
// Falls back to '*' only when unset (local dev), so production always requires it.
$allowedOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*'))
)));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
