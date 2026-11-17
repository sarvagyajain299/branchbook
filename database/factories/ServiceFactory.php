<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Service> */
class ServiceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->words(2, true),
            'category' => 'Accounts',
            'description' => fake()->sentence(),
            'duration_minutes' => 30,
            'buffer_minutes' => 0,
            'queue_prefix' => fake()->randomElement(['A', 'C', 'M', 'R', 'W', 'B']),
            'required_documents' => ['Passport'],
            'min_notice_hours' => 2,
            'max_days_ahead' => 30,
            'is_active' => true,
        ];
    }
}
