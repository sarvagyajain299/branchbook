<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Data\BookingRequestData;
use App\Exceptions\SlotUnavailable;
use App\Mail\OtpMail;
use App\Models\Appointment;
use App\Services\OtpService;
use Illuminate\Support\Facades\Mail;

/** Step 1 of a booking: hold the slot, then email the verification code. */
final class StartBooking
{
    public function __construct(
        private readonly HoldAppointment $hold,
        private readonly OtpService $otp,
    ) {}

    /** @throws SlotUnavailable */
    public function handle(BookingRequestData $data): Appointment
    {
        $appointment = $this->hold->handle($data);

        $this->sendCode($appointment, $data->email);

        return $appointment;
    }

    /** Also used by "resend code". */
    public function sendCode(Appointment $appointment, string $email): void
    {
        $email = mb_strtolower($email);
        $code = $this->otp->issue($email, OtpService::purposeFor($appointment->reference));

        Mail::to($email)->sendNow(new OtpMail($code, $appointment->reference, OtpService::TTL_MINUTES));
    }
}
