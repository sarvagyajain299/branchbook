<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Actions\Booking\CancelBooking;
use App\Enums\AppointmentStatus;
use App\Exceptions\CancellationNotAllowed;
use App\Http\Controllers\Controller;
use App\Http\Requests\Booking\CancelBookingRequest;
use App\Services\ManageToken;
use App\Support\IcsBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The private "manage my booking" page, reached from the confirmation email. The token in the
 * link is the only credential, so these responses are never cached and never leak the URL
 * through the Referer header.
 */
class ManageBookingController extends Controller
{
    public function show(Request $request, string $reference): Response
    {
        $appointment = ManageToken::resolve($reference, $request->query('token'));

        $canCancel = $appointment->status === AppointmentStatus::Confirmed
            && $appointment->starts_at->greaterThan(CarbonImmutable::now()->addHours(CancelBooking::CUTOFF_HOURS));

        return response()->view('booking.manage', [
            'appointment' => $appointment,
            'token' => (string) $request->query('token'),
            'canCancel' => $canCancel,
            'cutoffHours' => CancelBooking::CUTOFF_HOURS,
        ])->withHeaders($this->privateHeaders());
    }

    public function calendar(Request $request, string $reference): Response
    {
        $appointment = ManageToken::resolve($reference, $request->query('token'));

        return response(IcsBuilder::for($appointment), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="northwind-'.$reference.'.ics"',
        ] + $this->privateHeaders());
    }

    public function cancel(CancelBookingRequest $request, string $reference, CancelBooking $cancel): RedirectResponse
    {
        $token = $request->validated('token');
        $appointment = ManageToken::resolve($reference, $token);
        $to = redirect()->route('booking.show', ['reference' => $reference, 'token' => $token]);

        try {
            $cancel->handle($appointment, $request->validated('reason'));
        } catch (CancellationNotAllowed $e) {
            return $to->with('error', $e->getMessage());
        }

        return $to->with('status', 'Your appointment has been cancelled.');
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
    }
}
