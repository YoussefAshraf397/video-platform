<?php

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $resetUrl)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(text: 'auth::mail.reset-password');
    }
}
