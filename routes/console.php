<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hết hạn phiếu đặt trước mỗi ngày lúc 00:05
Schedule::command('reservations:expire')->dailyAt('00:05');
