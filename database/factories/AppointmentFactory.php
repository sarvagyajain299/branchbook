<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AppointmentChannel;
use App\Enums\AppointmentStatus;
use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Service;
use App\Support\BookingReference;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Appointment> */
class AppointmentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = now()->addDays(2)->setTime(5, 0); // 09:00 Dubai, in UTC

        return [
            'reference' => BookingReference::generate(),
            'customer_id' => Customer::factory(),
            'branch_id' => Branch::factory(),
            'service_id' => Service::factory(),
            'advisor_id' => Advisor::factory(),
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
            'channel' => AppointmentChannel::Online,
            'hold_expires_at' => null,
            'manage_token_hash' => hash('sha256', fake()->uuid()),
        ];
    }

    public function status(AppointmentStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
