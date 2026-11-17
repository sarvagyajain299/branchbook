<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QueueTicket> */
class QueueTicketFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(1, 900);

        return [
            'branch_id' => Branch::factory(),
            'service_date' => now()->toDateString(),
            'service_id' => Service::factory(),
            'queue_prefix' => 'A',
            'sequence' => $sequence,
            'display_code' => sprintf('A-%03d', $sequence),
            'appointment_id' => null,
            'status' => TicketStatus::Waiting,
            'issued_at' => now(),
        ];
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
