<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your HR Platform login code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.login-code',
            with: [
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
