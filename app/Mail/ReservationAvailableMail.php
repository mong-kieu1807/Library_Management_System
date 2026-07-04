<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ReservationAvailableMail extends Mailable
{
    use Queueable, SerializesModels;

    public $userName;
    public $bookTitle;
    public $libraryName;
    public $notifiedAt;
    public $expiresAt;

    /**
     * Create a new message instance.
     */
    public function __construct(string $userName, string $bookTitle, string $libraryName, string $notifiedAt, string $expiresAt)
    {
        $this->userName   = $userName;
        $this->bookTitle  = $bookTitle;
        $this->libraryName = $libraryName;
        $this->notifiedAt = $notifiedAt;
        $this->expiresAt  = $expiresAt;
    }

    /**
     * Build the message.
     */
    public function build()
    {
        return $this->subject('Sách bạn đặt trước đã có sẵn')
                    ->view('emails.reservation_available');
    }
}
