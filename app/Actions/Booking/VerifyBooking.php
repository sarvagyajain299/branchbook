<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Data\VerificationOutcome;
use App\Enums\AppointmentStatus;
use App\Enums\OtpResult;
use App\Enums\VerificationStatus;
use App\Mail\BookingConfirmedMail;
use App\Models\Appointment;
use App\Services\ManageToken;
use App\Services\OtpService;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Step 2: check the emailed code; on success confirm the booking and create the manage token. */
final class VerifyBooking
{
    public function __construct(private readonly OtpService $otp) {}

    public function handle(Appointment $appointment, string $code, ?CarbonImmutable $now = null): VerificationOutcome
    {
        $now ??= CarbonImmutable::now();

        if ($appointment->status !== AppointmentStatus::Held) {
            return new VerificationOutcome(VerificationStatus::NotPending);
        }

        if ($appointment->hold_expires_at === null || $appointment->hold_expires_at->lessThanOrEqualTo($now)) {
            $appointment->transitionTo(AppointmentStatus::Expired);

            return new VerificationOutcome(VerificationStatus::HoldExpired);
        }

        [$result, $left] = $this->otp->verify(
            $appointment->customer->email,
            OtpService::purposeFor($appointment->reference),
            $code,
            $now,
        );

        return match ($result) {
            OtpResult::Verified => $this->confirm($appointment, $now),
            OtpResult::Wrong => new VerificationOutcome(VerificationStatus::WrongCode, attemptsLeft: $left),
            OtpResult::LockedOut => new VerificationOutcome(VerificationStatus::LockedOut),
            OtpResult::Expired, OtpResult::Missing => new VerificationOutcome(VerificationStatus::CodeExpired),
        };
    }

    private function confirm(Appointment $appointment, CarbonImmutable $now): VerificationOutcome
    {
        $token = ManageToken::generate();

        DB::transaction(function () use ($appointment, $token, $now): void {
            $appointment->manage_token_hash = ManageToken::hash($token);
            $appointment->hold_expires_at = null;
            $appointment->transitionTo(AppointmentStatus::Confirmed);

            $appointment->customer->forceFill(['email_verified_at' => $now, 'last_activity_at' => $now])->save();

            Audit::record('appointment.confirmed', $appointment, ['by' => 'customer', 'status' => ['held', 'confirmed']]);
        });

        Mail::to($appointment->customer->email)->send(new BookingConfirmedMail($appointment, $token));

        return new VerificationOutcome(VerificationStatus::Confirmed, manageToken: $token);
    }
}
