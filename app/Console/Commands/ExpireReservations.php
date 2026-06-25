<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireReservations extends Command
{
    protected $signature   = 'reservations:expire';
    protected $description = 'Hết hạn các phiếu đặt trước ready đã quá expired_at.';

    public function handle(): int
    {
        $count = DB::table('reservations')
            ->where('status', 'ready')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->update(['status' => 'expired']);

        $this->info("Đã hết hạn {$count} phiếu đặt trước.");
        return Command::SUCCESS;
    }
}
