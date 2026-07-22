<?php

$envOrigins = (string) env('CORS_ALLOWED_ORIGINS', '');
$allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $envOrigins))));

$defaultOrigins = [
    'https://admin.libraryhub.dev',
    'https://libraryhub.dev',
];

$finalOrigins = empty($allowedOrigins)
    ? ['*']
    : array_values(array_unique(array_merge($defaultOrigins, $allowedOrigins)));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers.
    |
    */

    'paths' => ['api/*', 'v1/*', 'private/*', 'sanctum/csrf-cookie', '*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $finalOrigins,

    'allowed_origins_patterns' => [
        '#^https?://localhost(:[0-9]+)?$#',
        '#^https?://127\.0\.0\.1(:[0-9]+)?$#',
        '#^https://.*\.vercel\.app$#',
        '#^https://.*\.ondigitalocean\.app$#',
        '#^https://([a-zA-Z0-9-]+\.)*libraryhub\.dev$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['*'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
