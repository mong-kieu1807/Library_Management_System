<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

foreach ([
    'https://raw.githubusercontent.com/mong-kieu1807/library-assets/main/ngoi-khoc-tren-cay.jpg',
    'https://raw.githubusercontent.com/mong-kieu1807/library-assets/main/vua-nham-mat-vua-mo-cua-so.jpg'
] as $url) {
    $res = Http::head($url);
    echo $url . ': ' . $res->status() . "\n";
}
