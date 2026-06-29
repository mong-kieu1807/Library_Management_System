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
        $reservations = DB::table('reservations')
            ->where('status', 'ready')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->get();

        foreach ($reservations as $reservation) {

            DB::table('notifications')->insert([
                'user_id'    => $reservation->user_id,
                'title'      => 'Phiếu đặt trước đã hết hạn',
                'content'    => 'Phiếu đặt trước của bạn đã hết hạn do quá thời gian nhận sách.',
                'type'       => 'reservation',
                'is_read'    => 0,
                'created_at' => now(),
            ]);

            DB::table('reservations')
                ->where('reservation_id', $reservation->reservation_id)
                ->update([
                    'status' => 'expired'
                ]);
        }

        $this->info("Đã hết hạn {$reservations->count()} phiếu đặt trước.");

        return Command::SUCCESS;
    }
}
