<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Booking\CancelBooking;
use App\Actions\Booking\HoldAppointment;
use App\Actions\Booking\StartBooking;
use App\Actions\Booking\VerifyBooking;
use App\Data\BookingRequestData;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreBookingRequest;
use App\Http\Resources\AppointmentResource;
use App\Mail\BookingConfirmedMail;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Service;
use App\Services\ManageToken;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/** The same booking actions as the website; only the transport (JSON, header token) differs. */
class BookingController extends Controller
{
    /** POST /bookings: holds the slot for 10 minutes and emails a 6-digit code. */
    public function store(StoreBookingRequest $request, StartBooking $start): JsonResponse
    {
        $appointment = $start->handle(new BookingRequestData(
            service: Service::query()->where('code', $request->validated('service'))->firstOrFail(),
            branch: Branch::query()->where('code', $request->validated('branch'))->firstOrFail(),
            startsAt: CarbonImmutable::parse($request->validated('starts_at'))->utc(),
            firstName: $request->validated('first_name'),
            lastName: $request->validated('last_name'),
            email: $request->validated('email'),
            phone: $request->phoneE164(),
            marketingOptIn: $request->boolean('marketing_opt_in'),
        ));

        return (new AppointmentResource($appointment->load(['service', 'branch'])))
            ->additional(['message' => 'Verification code sent. Confirm within 10 minutes.'])
            ->response()->setStatusCode(201);
    }

    /** POST /bookings/{reference}/verify {"code": "123456"} -> confirms and returns the manage token ONCE. */
    public function verify(Request $request, string $reference, VerifyBooking $verify): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $appointment = Appointment::query()->with(['customer', 'service', 'branch'])->where('reference', $reference)->firstOrFail();
        $outcome = $verify->handle($appointment, (string) $request->input('code'));

        return match ($outcome->status) {
            VerificationStatus::Confirmed => (new AppointmentResource($appointment->refresh()->load(['service', 'branch'])))
                ->additional(['manage_token' => $outcome->manageToken, 'message' => 'Appointment confirmed. Keep the manage token: it is shown only once.'])
                ->response(),
            VerificationStatus::WrongCode => response()->json(['message' => 'The code is incorrect.', 'errors' => ['code' => ["{$outcome->attemptsLeft} attempt(s) left."]]], 422),
            VerificationStatus::LockedOut => response()->json(['message' => 'Too many wrong attempts.', 'errors' => ['code' => ['Locked out: create the booking again.']]], 422),
            VerificationStatus::CodeExpired => response()->json(['message' => 'The code has expired.', 'errors' => ['code' => ['Expired.']]], 422),
            VerificationStatus::HoldExpired => response()->json(['message' => 'The 10-minute hold has ended. Please book again.'], 410),
            VerificationStatus::NotPending => response()->json(['message' => 'This booking is not waiting for verification.'], 409),
        };
    }

    /** GET /bookings/{reference} with header X-Manage-Token. */
    public function show(Request $request, string $reference): AppointmentResource
    {
        return new AppointmentResource(ManageToken::resolve($reference, $request->header('X-Manage-Token')));
    }

    /** PATCH /bookings/{reference} {"starts_at": "..."} with X-Manage-Token: moves the booking; returns a NEW reference and token. */
    public function update(Request $request, string $reference, HoldAppointment $hold): JsonResponse
    {
        $data = $request->validate(['starts_at' => ['required', 'date']]);
        $appointment = ManageToken::resolve($reference, $request->header('X-Manage-Token'));

        [$new, $token] = $hold->reschedule($appointment, CarbonImmutable::parse($data['starts_at'])->utc());

        Mail::to($new->customer->email)->send(new BookingConfirmedMail($new->load(['customer', 'service', 'branch']), $token));

        return (new AppointmentResource($new))
            ->additional(['manage_token' => $token, 'message' => 'Appointment moved. The old reference is cancelled; use the new reference and manage token.'])
            ->response()->setStatusCode(200);   // the resource wraps a just-created model, which would default to 201
    }

    /** DELETE /bookings/{reference} with header X-Manage-Token (optional JSON {"reason": "..."}). */
    public function destroy(Request $request, string $reference, CancelBooking $cancel): AppointmentResource
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;
        $appointment = ManageToken::resolve($reference, $request->header('X-Manage-Token'));

        $cancel->handle($appointment, $reason);

        return new AppointmentResource($appointment->refresh());
    }
}
