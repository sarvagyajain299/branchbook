<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use App\Models\BranchHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchHour> */
class BranchHourFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'weekday' => 1,
            'opens_at' => '08:00',
            'closes_at' => '15:00',
            'break_start' => null,
            'break_end' => null,
        ];
    }
}
