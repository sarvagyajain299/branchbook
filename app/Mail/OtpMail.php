<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Deliberately NOT queued: a queued mailable would store the plain code in the jobs table.
 * It is sent immediately with Mail::sendNow().
 */
final class OtpMail extends Mailable
{
    public function __construct(
        public readonly string $code,
        public readonly string $reference,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Northwind Bank verification code');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.otp');
    }
}
