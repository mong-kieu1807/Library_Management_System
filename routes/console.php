<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hết hạn phiếu đặt trước mỗi ngày lúc 00:05
Schedule::command('reservations:expire')->dailyAt('00:05');
Schedule::command('books:remind-3days')->dailyAt('08:00');
Schedule::command('books:remind-1days')->dailyAt('08:00');

Schedule::command('books:overdue-reminder')->dailyAt('08:10');

// Module 3 — Làm mới cache AI phân tích nhu cầu sách mỗi đêm
Schedule::call(function () {
    app(\App\Services\AIBookDemandService::class)->clearCache();
})->dailyAt('02:00')->name('ai:refresh-demand-cache')->withoutOverlapping();