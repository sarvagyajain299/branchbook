<?php

declare(strict_types=1);

namespace App\Http\Controllers\Booking;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\HoldAppointment;
use App\Data\Slot;
use App\Enums\AppointmentStatus;
use App\Exceptions\RescheduleNotAllowed;
use App\Exceptions\SlotUnavailable;
use App\Http\Controllers\Controller;
use App\Mail\BookingConfirmedMail;
use App\Models\Appointment;
use App\Services\Availability\AvailabilityService;
use App\Services\Availability\WeekPayload;
use App\Services\ManageToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Mail;

/** Move a confirmed booking to another time. Same token rules as the manage page. */
class RescheduleBookingController extends Controller
{
    /** The slot picker, for the same service and branch as the current booking. */
    public function show(Request $request, string $reference): Response|RedirectResponse
    {
        $appointment = ManageToken::resolve($reference, $request->query('token'));

        if ($blocked = $this->blocked($appointment, (string) $request->query('token'))) {
            return $blocked;
        }

        return response()->view('booking.reschedule', [
            'appointment' => $appointment,
            'token' => (string) $request->query('token'),
            'today' => CarbonImmutable::now((string) config('app.display_timezone'))->toDateString(),
        ])->withHeaders($this->privateHeaders());
    }

    public function slots(Request $request, string $reference, WeekPayload $week): JsonResponse
    {
        $appointment = ManageToken::resolve($reference, $request->query('token'));
        $validated = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'days' => ['nullable', 'integer', 'between:1,14']]);
        $token = (string) $request->query('token');

        return response()->json($week->build(
            $appointment->branch, $appointment->service, $validated['from'] ?? null, (int) ($validated['days'] ?? 7),
            fn (Slot $slot): string => route('booking.reschedule.confirm', ['reference' => $reference, 'token' => $token, 'starts_at' => $slot->startsAt->toIso8601ZuluString()]),
        ))->withHeaders($this->privateHeaders());
    }

    /** "Move from X to Y?" with a confirm button. */
    public function confirm(Request $request, string $reference, AvailabilityService $availability): Response|RedirectResponse
    {
        $appointment = ManageToken::resolve($reference, $request->query('token'));
        $token = (string) $request->query('token');
        $startsAt = CarbonImmutable::parse($request->validate(['starts_at' => ['required', 'date']])['starts_at'])->utc();

        if ($blocked = $this->blocked($appointment, $token)) {
            return $blocked;
        }

        if ($availability->freeAdvisorsAt($appointment->branch, $appointment->service, $startsAt)->isEmpty()) {
            return redirect()->route('booking.reschedule', ['reference' => $reference, 'token' => $token])->with('error', (new SlotUnavailable)->getMessage());
        }

        return response()->view('booking.reschedule-confirm', [
            'appointment' => $appointment,
            'token' => $token,
            'startsAt' => $startsAt,
            'newLocal' => $startsAt->setTimezone((string) config('app.display_timezone')),
        ])->withHeaders($this->privateHeaders());
    }

    public function perform(Request $request, string $reference, HoldAppointment $hold): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100'], 'starts_at' => ['required', 'date']]);
        $appointment = ManageToken::resolve($reference, $data['token']);
        $back = redirect()->route('booking.reschedule', ['reference' => $reference, 'token' => $data['token']]);

        try {
            [$new, $newToken] = $hold->reschedule($appointment, CarbonImmutable::parse($data['starts_at'])->utc());
        } catch (SlotUnavailable|RescheduleNotAllowed $e) {
            return $back->with('error', $e->getMessage());
        }

        Mail::to($new->customer->email)->send(new BookingConfirmedMail($new->load(['customer', 'service', 'branch']), $newToken));

        return redirect()
            ->route('booking.show', ['reference' => $new->reference, 'token' => $newToken])
            ->with('status', 'Your appointment has been moved. We have emailed you the new details.');
    }

    /** Sends the customer back to the manage page with an explanation when moving isn't possible. */
    private function blocked(Appointment $appointment, string $token): ?RedirectResponse
    {
        $manage = redirect()->route('booking.show', ['reference' => $appointment->reference, 'token' => $token]);

        if ($appointment->status !== AppointmentStatus::Confirmed) {
            return $manage->with('error', RescheduleNotAllowed::notActive()->getMessage());
        }

        if ($appointment->starts_at->lessThanOrEqualTo(CarbonImmutable::now()->addHours(CancelBooking::CUTOFF_HOURS))) {
            return $manage->with('error', RescheduleNotAllowed::tooLate(CancelBooking::CUTOFF_HOURS)->getMessage());
        }

        return null;
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer'];
    }
}
