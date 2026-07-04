<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use App\Services\WeeklySummaryService;
use App\Mail\WeeklySummaryMail;

class SendWeeklySummary extends Command
{
    protected $signature = 'library:weekly-summary';
    protected $description = 'Gửi email báo cáo mượn sách hàng tuần cho độc giả';

    public function __construct(protected WeeklySummaryService $weeklySummaryService)
    {
        parent::__construct();
    }

    public function handle()
    {
        $readers = $this->weeklySummaryService->getActiveReaders();

        if ($readers->isEmpty()) {
            $this->info('Không có độc giả hoạt động để gửi báo cáo.');
            return 0;
        }

        foreach ($readers as $reader) {
            if (!$reader->email) {
                continue;
            }

            $summary = $this->weeklySummaryService->getSummaryForUser($reader);

            Mail::to($reader->email)->send(new WeeklySummaryMail($reader, $summary));
            $this->info('Đã gửi cho: ' . $reader->email);
        }

        $this->info('Hoàn thành gửi báo cáo mượn sách hàng tuần.');

        return 0;
    }
}
