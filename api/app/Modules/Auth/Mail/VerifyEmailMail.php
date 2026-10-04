<?php

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class VerifyEmailMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name, public readonly string $verifyUrl)
    {
        $this->afterCommit();   // never send for a registration that rolled back
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your email address');
    }

    public function content(): Content
    {
        return new Content(text: 'auth::mail.verify-email');
    }
}
