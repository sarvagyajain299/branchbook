<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Advisor;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Advisor> */
class AdvisorFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->forBranch(Role::Advisor),
            'branch_id' => Branch::factory(),
            'desk_label' => 'Desk '.fake()->numberBetween(1, 12),
            'is_active' => true,
        ];
    }
}
