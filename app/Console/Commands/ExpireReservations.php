<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Reservation;
use App\Models\Notification;
use App\Models\BookCopy;

class ExpireReservations extends Command
{
    protected $signature   = 'reservations:expire';
    protected $description = 'Hết hạn các phiếu đặt trước ready_for_pickup đã quá pickup_deadline và giải phóng bản sao đã khóa.';

    public function handle(): int
    {
        $expired = Reservation::where('status', 'ready_for_pickup')
            ->whereNotNull('pickup_deadline')
            ->where('pickup_deadline', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Không có đặt trước ready_for_pickup nào quá hạn.');
            return Command::SUCCESS;
        }

        foreach ($expired as $res) {
            try {
                DB::beginTransaction();

                $res->status = 'expired';
                $res->save();
                Log::info("Reservation expired: id={$res->reservation_id}, user_id={$res->user_id}, book_id={$res->book_id}, copy_id={$res->copy_id}");

                // Giải phóng bản sao đã khóa cho reservation này về lại available
                if ($res->copy_id) {
                    BookCopy::where('copy_id', $res->copy_id)
                        ->where('status', 'reserved')
                        ->update(['status' => 'available']);
                }

                $expireTitle = 'Phiếu đặt trước đã hết hạn';
                $expireContent = 'Phiếu đặt trước của bạn đã hết hạn do quá thời gian nhận sách.';
                $existsExpire = Notification::where('user_id', $res->user_id)
                    ->where('title', $expireTitle)
                    ->where('type', 'reservation')
                    ->where('content', $expireContent)
                    ->exists();
                if (! $existsExpire) {
                    Notification::create([
                        'user_id'    => $res->user_id,
                        'title'      => $expireTitle,
                        'content'    => $expireContent,
                        'type'       => 'reservation',
                        'is_read'    => 0,
                        'created_at' => now(),
                    ]);
                }

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Error processing expired reservation id=' . $res->reservation_id . ' - ' . $e->getMessage());
            }
        }

        $this->info("Đã xử lý {$expired->count()} phiếu đặt trước quá hạn.");

        return Command::SUCCESS;
    }
}
