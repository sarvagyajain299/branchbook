<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Closure;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Closure> */
class ClosureFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => null,   // all branches
            'date' => fake()->dateTimeBetween('+1 month', '+1 year')->format('Y-m-d'),
            'reason' => 'Public holiday',
        ];
    }
}
