<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use App\Mail\ReservationAvailableMail;
use App\Models\Reservation;
use App\Models\Notification;
use App\Models\Book;
use App\Models\User;

class ExpireReservations extends Command
{
    protected $signature   = 'reservations:expire';
    protected $description = 'Hết hạn các phiếu đặt trước ready đã quá expired_at.';

    public function handle(): int
    {
        $expired = Reservation::where('status', 'ready')
            ->whereNotNull('expired_at')
            ->where('expired_at', '<', now())
            ->get();

        if ($expired->isEmpty()) {
            $this->info('Không có đặt trước ready nào quá hạn.');
            return Command::SUCCESS;
        }

        foreach ($expired as $res) {
            try {
                DB::beginTransaction();

                // mark current as expired
                $res->status = 'expired';
                $res->save();
                Log::info("Reservation expired: id={$res->reservation_id}, user_id={$res->user_id}, book_id={$res->book_id}");

                // create notification for previous user (avoid duplicate)
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

                // find next waiting reservation for same book (FIFO)
                $query = Reservation::where('book_id', $res->book_id)
                    ->where('status', 'waiting');

                // prefer queue_position if column exists
                if (Schema::hasColumn('reservations', 'queue_position')) {
                    $query->orderBy('queue_position')->orderBy('created_at');
                } else {
                    $query->orderBy('created_at');
                }

                $next = $query->first();

                if ($next) {
                    $now = now();
                    $expires = $now->copy()->addDays(2);

                    $next->status = 'ready';
                    $next->notified_at = $now;
                    $next->expired_at = $expires;
                    $next->save();

                    Log::info("Promoted next reservation: id={$next->reservation_id}, user_id={$next->user_id}");

                    // send email using existing mailable
                    $user = User::find($next->user_id);
                    $book = Book::find($next->book_id);

                    $libraryName = config('app.name') ?? 'Thư Viện';

                    $notifiedAt = $now->toDateTimeString();
                    $expiresAt  = $expires->toDateTimeString();

                    try {
                        Mail::to($user->email)
                            ->send(new ReservationAvailableMail($user->full_name, $book->title, $libraryName, $notifiedAt, $expiresAt));
                        Log::info("ReservationAvailableMail sent to user_id={$user->user_id}, email={$user->email}");
                    } catch (\Exception $e) {
                        Log::error('Failed to send ReservationAvailableMail: ' . $e->getMessage());
                    }

                    // create in-app notification for next user (avoid duplicate)
                    $readyTitle = 'Sách đặt trước đã có sẵn';
                    $readyContent = 'Sách "' . ($book->title ?? '') . '" đã sẵn sàng. Bạn có 2 ngày để đến thư viện nhận sách.';
                    $existsReady = Notification::where('user_id', $next->user_id)
                        ->where('title', $readyTitle)
                        ->where('type', 'reservation')
                        ->where('content', $readyContent)
                        ->exists();
                    if (! $existsReady) {
                        Notification::create([
                            'user_id'    => $next->user_id,
                            'title'      => $readyTitle,
                            'content'    => $readyContent,
                            'type'       => 'reservation',
                            'is_read'    => 0,
                            'created_at' => now(),
                        ]);
                    }

                } else {
                    Log::info("No next reservation for book_id={$res->book_id}");
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
