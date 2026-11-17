<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('???')).'-'.strtoupper(fake()->lexify('???')),
            'name' => 'Northwind '.fake()->streetName(),
            'city' => fake()->randomElement(['Dubai', 'Abu Dhabi', 'Sharjah']),
            'address' => fake()->streetAddress(),
            'latitude' => fake()->latitude(24.0, 25.5),
            'longitude' => fake()->longitude(54.0, 55.6),
            'phone' => '+9714'.fake()->numerify('#######'),
            'is_active' => true,
        ];
    }
}
