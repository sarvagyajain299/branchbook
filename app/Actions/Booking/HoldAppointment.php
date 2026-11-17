<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Maintenance\ExpireStaleHolds;
use App\Data\BookingRequestData;
use App\Enums\AppointmentChannel;
use App\Enums\AppointmentStatus;
use App\Exceptions\RescheduleNotAllowed;
use App\Exceptions\SlotUnavailable;
use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Customer;
use App\Services\Assignment\AdvisorAssignmentStrategy;
use App\Services\Availability\AvailabilityService;
use App\Services\ManageToken;
use App\Support\Audit;
use App\Support\BookingReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Places a booking on an advisor's calendar. Used in two ways:
 *  - handle(): a NEW booking, held for 10 minutes while the customer verifies their email;
 *  - reschedule(): moves a CONFIRMED booking, creating the new confirmed appointment and
 *    releasing the old one inside the same transaction.
 *
 * Double-booking defence, in layers:
 *  1. availability is computed from fresh data;
 *  2. a transaction LOCKS the chosen advisor's row (SELECT ... FOR UPDATE), so a competing
 *     booking for the same advisor waits;
 *  3. under the lock, availability is checked AGAIN. This is the check that catches the
 *     race: whoever got the lock second now sees the first booking;
 *  4. the unique index on appointments.active_slot_key rejects the insert even if 1-3 had a bug.
 * Both 3 and 4 end in the same friendly "slot was just taken" error.
 */
final class HoldAppointment
{
    public const HOLD_MINUTES = 10;

    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AdvisorAssignmentStrategy $strategy,
        private readonly ExpireStaleHolds $expireStaleHolds,
    ) {}

    /** @throws SlotUnavailable */
    public function handle(BookingRequestData $data, ?CarbonImmutable $now = null): Appointment
    {
        return $this->place($data, $now ?? CarbonImmutable::now(), null, null);
    }

    /**
     * Moves a confirmed booking to a new time at the same branch for the same service.
     *
     * The NEW slot is booked first and the OLD one released afterwards, in one transaction:
     * if the new slot is gone, nothing changes and the customer keeps their original time.
     * A fresh manage token is issued for the new booking.
     *
     * @return array{0: Appointment, 1: string} the new appointment and its plain manage token
     *
     * @throws RescheduleNotAllowed
     * @throws SlotUnavailable
     */
    public function reschedule(Appointment $old, CarbonImmutable $newStart, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $old->loadMissing(['customer', 'service', 'branch']);

        $this->assertMayReschedule($old, $newStart, $now);

        $token = ManageToken::generate();
        $customer = $old->customer;

        $new = $this->place(new BookingRequestData(
            service: $old->service,
            branch: $old->branch,
            startsAt: $newStart,
            firstName: $customer->first_name,
            lastName: $customer->last_name,
            email: $customer->email,
            phone: $customer->phone,
            marketingOptIn: $customer->marketing_opt_in,
        ), $now, $old, ManageToken::hash($token));

        return [$new, $token];
    }

    private function assertMayReschedule(Appointment $old, CarbonImmutable $newStart, CarbonImmutable $now): void
    {
        if ($old->status !== AppointmentStatus::Confirmed) {
            throw RescheduleNotAllowed::notActive();
        }

        if ($old->starts_at->lessThanOrEqualTo($now->addHours(CancelBooking::CUTOFF_HOURS))) {
            throw RescheduleNotAllowed::tooLate(CancelBooking::CUTOFF_HOURS);
        }

        if ($newStart->equalTo($old->starts_at)) {
            throw RescheduleNotAllowed::sameTime();
        }
    }

    private function place(BookingRequestData $data, CarbonImmutable $now, ?Appointment $replacing, ?string $tokenHash): Appointment
    {
        $candidates = $this->availability->freeAdvisorsAt($data->branch, $data->service, $data->startsAt, $now);

        if ($candidates->isEmpty()) {
            throw new SlotUnavailable;
        }

        // The strategy's pick goes first; if it loses a race, fall back to the other free advisors.
        $preferred = $this->strategy->choose($candidates, $data->startsAt);
        $ordered = $candidates->sortBy(fn (Advisor $a): int => $a->is($preferred) ? 0 : 1)->values();

        foreach ($ordered as $advisor) {
            try {
                return $this->attempt($advisor, $data, $now, $replacing, $tokenHash);
            } catch (SlotUnavailable|UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new SlotUnavailable;
    }

    private function attempt(Advisor $advisor, BookingRequestData $data, CarbonImmutable $now, ?Appointment $replacing, ?string $tokenHash): Appointment
    {
        return DB::transaction(function () use ($advisor, $data, $now, $replacing, $tokenHash): Appointment {
            // First statement in the transaction: take the row lock. Anything read AFTER it
            // reflects every booking committed by whoever held the lock before us.
            Advisor::query()->whereKey($advisor->id)->lockForUpdate()->firstOrFail();

            // For a reschedule, also lock the booking being replaced so it can't be cancelled,
            // checked in or moved twice by someone else in the meantime.
            $current = null;
            if ($replacing !== null) {
                $current = Appointment::query()->whereKey($replacing->id)->lockForUpdate()->firstOrFail();

                if ($current->status !== AppointmentStatus::Confirmed) {
                    throw RescheduleNotAllowed::notActive();
                }
            }

            // A lapsed hold still occupies the unique slot index until marked expired.
            $this->expireStaleHolds->forAdvisor($advisor->id, $now);

            $stillFree = $this->availability
                ->freeAdvisorsAt($data->branch, $data->service, $data->startsAt, $now)
                ->contains('id', $advisor->id);

            if (! $stillFree) {
                throw new SlotUnavailable;
            }

            $customer = $replacing !== null ? $replacing->customer : $this->customer($data, $now);

            $appointment = new Appointment([
                'reference' => BookingReference::generate(),
                'customer_id' => $customer->id,
                'branch_id' => $data->branch->id,
                'service_id' => $data->service->id,
                'advisor_id' => $advisor->id,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->startsAt->addMinutes($data->service->duration_minutes),
                'channel' => AppointmentChannel::Online,
                'hold_expires_at' => $replacing === null ? $now->addMinutes(self::HOLD_MINUTES) : null,
                'manage_token_hash' => $tokenHash,
                'rescheduled_from_id' => $replacing?->id,
            ]);
            // status is not mass-assignable: set it explicitly.
            $appointment->status = $replacing === null ? AppointmentStatus::Held : AppointmentStatus::Confirmed;
            $appointment->save();

            if ($current !== null) {
                // The old slot is released only now, after the new one is safely booked.
                $current->cancelled_reason = "Rescheduled to {$appointment->reference}";
                $current->transitionTo(AppointmentStatus::Cancelled);

                Audit::record('appointment.rescheduled', $appointment, ['by' => 'customer', 'from' => $current->reference, 'to' => $appointment->reference]);
            }

            return $appointment;
        });
    }

    private function customer(BookingRequestData $data, CarbonImmutable $now): Customer
    {
        $customer = Customer::query()->firstOrNew(['email' => mb_strtolower($data->email)]);
        $customer->fill([
            'first_name' => $data->firstName,
            'last_name' => $data->lastName,
            'phone' => $data->phone,
            'marketing_opt_in' => $data->marketingOptIn,
            'last_activity_at' => $now,
        ])->save();

        return $customer;
    }
}
