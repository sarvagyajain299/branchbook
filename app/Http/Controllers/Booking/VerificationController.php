<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Actions\Booking\StartBooking;
use App\Actions\Booking\VerifyBooking;
use App\Enums\AppointmentStatus;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\VerifyOtpRequest;
use App\Models\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Step 5: enter the emailed code. Only the browser that started the booking may see this page. */
class VerificationController extends Controller
{
    public function show(Request $request, string $reference): View|RedirectResponse
    {
        $appointment = $this->pending($request, $reference);

        if ($appointment === null) {
            return $this->restart();
        }

        return view('booking.verify', [
            'appointment' => $appointment,
            'maskedEmail' => $appointment->customer->maskedEmail(),
        ]);
    }

    public function verify(VerifyOtpRequest $request, string $reference, VerifyBooking $verify): RedirectResponse
    {
        $appointment = $this->pending($request, $reference);

        if ($appointment === null) {
            return $this->restart();
        }

        $outcome = $verify->handle($appointment, $request->validated('code'));

        return match ($outcome->status) {
            VerificationStatus::Confirmed => $this->confirmed($request, $appointment, (string) $outcome->manageToken),
            VerificationStatus::WrongCode => back()->withErrors(['code' => "That code isn't correct. {$outcome->attemptsLeft} attempt(s) left."]),
            VerificationStatus::LockedOut => back()->withErrors(['code' => 'Too many wrong attempts. Request a new code below.']),
            VerificationStatus::CodeExpired => back()->withErrors(['code' => 'That code has expired. Request a new one below.']),
            VerificationStatus::HoldExpired, VerificationStatus::NotPending => $this->restart('Your 10-minute hold on this time has ended. Please choose a time again.'),
        };
    }

    public function resend(Request $request, string $reference, StartBooking $startBooking): RedirectResponse
    {
        $appointment = $this->pending($request, $reference);

        if ($appointment === null) {
            return $this->restart();
        }

        $startBooking->sendCode($appointment, $appointment->customer->email);

        return back()->with('status', 'A new code is on its way. It replaces the previous one.');
    }

    private function pending(Request $request, string $reference): ?Appointment
    {
        if ($request->session()->get('booking.pending') !== $reference) {
            return null;
        }

        $appointment = Appointment::query()->with(['customer', 'branch', 'service'])->where('reference', $reference)->first();

        return $appointment !== null && $appointment->status === AppointmentStatus::Held ? $appointment : null;
    }

    private function confirmed(Request $request, Appointment $appointment, string $token): RedirectResponse
    {
        $request->session()->forget('booking.pending');

        return redirect()
            ->route('booking.show', ['reference' => $appointment->reference, 'token' => $token])
            ->with('status', 'Your appointment is confirmed. We have emailed you the details.');
    }

    private function restart(string $message = 'Your booking session has ended. Please start again.'): RedirectResponse
    {
        return redirect()->route('book.services')->with('error', $message);
    }
}
