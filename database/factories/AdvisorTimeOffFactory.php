<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Advisor;
use App\Models\AdvisorTimeOff;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdvisorTimeOff> */
class AdvisorTimeOffFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $start = now()->addDays(3)->setTime(4, 0);

        return [
            'advisor_id' => Advisor::factory(),
            'starts_at' => $start,
            'ends_at' => $start->addHours(8),
            'reason' => 'Annual leave',
        ];
    }
}
