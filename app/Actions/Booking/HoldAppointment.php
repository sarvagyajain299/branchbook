<?php

declare(strict_types=1);

namespace App\Actions\Booking;

use App\Actions\Maintenance\ExpireStaleHolds;
use App\Data\BookingRequestData;
use App\Enums\AppointmentChannel;
use App\Enums\AppointmentStatus;
use App\Exceptions\SlotUnavailable;
use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Customer;
use App\Services\Assignment\AdvisorAssignmentStrategy;
use App\Services\Availability\AvailabilityService;
use App\Support\BookingReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reserves a slot for 10 minutes (status "held") while the customer verifies their email.
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
        $now ??= CarbonImmutable::now();

        $candidates = $this->availability->freeAdvisorsAt($data->branch, $data->service, $data->startsAt, $now);

        if ($candidates->isEmpty()) {
            throw new SlotUnavailable;
        }

        // The strategy's pick goes first; if it loses a race, fall back to the other free advisors.
        $preferred = $this->strategy->choose($candidates, $data->startsAt);
        $ordered = $candidates->sortBy(fn (Advisor $a): int => $a->is($preferred) ? 0 : 1)->values();

        foreach ($ordered as $advisor) {
            try {
                return $this->attempt($advisor, $data, $now);
            } catch (SlotUnavailable|UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new SlotUnavailable;
    }

    private function attempt(Advisor $advisor, BookingRequestData $data, CarbonImmutable $now): Appointment
    {
        return DB::transaction(function () use ($advisor, $data, $now): Appointment {
            // First statement in the transaction: take the row lock. Anything read AFTER it
            // reflects every booking committed by whoever held the lock before us.
            Advisor::query()->whereKey($advisor->id)->lockForUpdate()->firstOrFail();

            // A lapsed hold still occupies the unique slot index until marked expired.
            $this->expireStaleHolds->forAdvisor($advisor->id, $now);

            $stillFree = $this->availability
                ->freeAdvisorsAt($data->branch, $data->service, $data->startsAt, $now)
                ->contains('id', $advisor->id);

            if (! $stillFree) {
                throw new SlotUnavailable;
            }

            $customer = Customer::query()->firstOrNew(['email' => mb_strtolower($data->email)]);
            $customer->fill([
                'first_name' => $data->firstName,
                'last_name' => $data->lastName,
                'phone' => $data->phone,
                'marketing_opt_in' => $data->marketingOptIn,
                'last_activity_at' => $now,
            ])->save();

            $appointment = new Appointment([
                'reference' => BookingReference::generate(),
                'customer_id' => $customer->id,
                'branch_id' => $data->branch->id,
                'service_id' => $data->service->id,
                'advisor_id' => $advisor->id,
                'starts_at' => $data->startsAt,
                'ends_at' => $data->startsAt->addMinutes($data->service->duration_minutes),
                'channel' => AppointmentChannel::Online,
                'hold_expires_at' => $now->addMinutes(self::HOLD_MINUTES),
            ]);
            // status is not mass-assignable: set it explicitly.
            $appointment->status = AppointmentStatus::Held;
            $appointment->save();

            return $appointment;
        });
    }
}
