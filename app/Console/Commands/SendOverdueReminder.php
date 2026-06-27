<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SendOverdueReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'books:overdue-reminder';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Gửi email thông báo sách quá hạn';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $borrows = BorrowTransaction::with('user')
            ->where('status', 'borrowed')
            ->whereDate('due_date', '<', now())
            ->get();

        foreach ($borrows as $borrow) {

            if (!$borrow->user || !$borrow->user->email) {
                continue;
            }

            $daysLate = now()->diffInDays($borrow->due_date);

            $fine = Fine::where('borrow_id', $borrow->borrow_id)
                ->latest()
                ->first();

            $amount = $fine ? $fine->amount : ($daysLate * 1000);

            Mail::raw(
                "Xin chào {$borrow->user->full_name},

    Bạn đang có sách quá hạn trả.

    Hạn trả: {$borrow->due_date}
    Số ngày quá hạn: {$daysLate} ngày

    Phí hiện tại: " . number_format($amount) . " VNĐ

    Vui lòng trả sách sớm để tránh phát sinh thêm phí.

    Thư viện xin cảm ơn.",
                function ($message) use ($borrow) {
                    $message
                        ->to($borrow->user->email)
                        ->subject('Thông báo sách quá hạn');
                }
            );

            $this->info("Đã gửi: {$borrow->user->email}");
        }

        $this->info('Hoàn thành.');
    }
}
