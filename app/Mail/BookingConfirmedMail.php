<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Appointment;
use App\Support\IcsBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Queued, so the customer's request doesn't wait on the mail server. It carries the secret
 * manage-booking token, so it implements ShouldBeEncrypted: the job payload in the queue
 * table is encrypted with APP_KEY and the token is never stored in plain text.
 */
final class BookingConfirmedMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Appointment $appointment,
        public readonly string $manageToken,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Northwind Bank appointment is confirmed: '.$this->appointment->reference);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.booking-confirmed', with: [
            'manageUrl' => route('booking.show', ['reference' => $this->appointment->reference, 'token' => $this->manageToken]),
            'when' => $this->appointment->localStart()->format('l j F Y, H:i').' (Dubai time)',
        ]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => IcsBuilder::for($this->appointment), 'northwind-appointment.ics')
                ->withMime('text/calendar'),
        ];
    }
}
