<?php

namespace App\Modules\Auth\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent instead of an error when someone signs up with a registered email. */
final class AccountAlreadyExistsMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $name)
    {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'You already have an account');
    }

    public function content(): Content
    {
        return new Content(text: 'auth::mail.account-already-exists');
    }
}
