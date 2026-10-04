<?php

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Security notice, so the owner learns about a reset they didn't make. */
final class PasswordChangedMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your password was changed');
    }

    public function content(): Content
    {
        return new Content(text: 'auth::mail.password-changed');
    }
}
