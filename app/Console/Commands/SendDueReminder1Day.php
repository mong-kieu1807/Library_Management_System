<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BorrowTransaction;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class SendDueReminder1Day extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
      protected $signature = 'books:remind-1days';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
  public function handle()
    {
        $targetDate = Carbon::today()->addDays(1);

        $borrows = BorrowTransaction::with('user')
            ->whereDate('due_date', $targetDate)
            ->where('status', 'borrowed')
            ->get();

        $this->info('Tìm thấy: ' . $borrows->count() . ' phiếu');

        foreach ($borrows as $borrow) {

            if (!$borrow->user || !$borrow->user->email) {
                continue;
            }

            $this->info('Đang gửi tới: ' . $borrow->user->email);

            Mail::raw(
                "Xin chào {$borrow->user->full_name},

    Sách bạn đang mượn sẽ đến hạn trả vào ngày {$borrow->due_date}.

    Vui lòng trả sách đúng hạn để tránh phát sinh phí quá hạn.

    Thư viện xin cảm ơn.",
                function ($message) use ($borrow) {
                    $message
                        ->to($borrow->user->email)
                        ->subject('Nhắc trả sách trước 1 ngày');
                }
            );

            $this->info('Gửi thành công');
        }

        $this->info('Đã gửi email nhắc trả sách.');
    }
}
