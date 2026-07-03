<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hết hạn phiếu đặt trước mỗi ngày lúc 00:05
// For testing: run hourly. Change to ->dailyAt('00:05') for production.
Schedule::command('reservations:expire')->hourly();
Schedule::command('books:remind-3days')->dailyAt('08:00');
Schedule::command('books:remind-1days')->dailyAt('08:00');
Schedule::command('library:weekly-summary')->weekly()->mondays()->at('08:00');

Schedule::command('books:overdue-reminder')->dailyAt('08:10');