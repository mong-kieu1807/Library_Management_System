<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Http;

foreach (['9786041218994', '9786041230316'] as $isbn) {
    $res = Http::get('https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn)->json();
    $thumbnail = $res['items'][0]['volumeInfo']['imageLinks']['thumbnail'] ?? 'not found';
    echo "$isbn: $thumbnail\n";
}
