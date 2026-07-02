<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\User;

class WeeklySummaryMail extends Mailable
{
    use SerializesModels;

    public User $user;
    public array $summary;

    public function __construct(User $user, array $summary)
    {
        $this->user = $user;
        $this->summary = $summary;
    }

    public function build()
    {
        return $this->subject('[Tư viện] Báo cáo mượn sách hàng tuần')
            ->view('emails.weekly_summary')
            ->with([
                'fullName' => $this->user->full_name,
                'borrowedCount' => $this->summary['borrowed_count'],
                'closestDueDate' => $this->summary['closest_due_date'],
                'unpaidFine' => number_format($this->summary['unpaid_fine'], 0, ',', '.') . ' VNĐ',
                'dueSoon' => $this->summary['due_soon'],
            ]);
    }
}
