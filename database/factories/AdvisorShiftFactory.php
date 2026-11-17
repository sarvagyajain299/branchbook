<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Advisor;
use App\Models\AdvisorShift;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdvisorShift> */
class AdvisorShiftFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'advisor_id' => Advisor::factory(),
            'weekday' => 1,
            'starts_at' => '08:00',
            'ends_at' => '15:00',
        ];
    }
}
