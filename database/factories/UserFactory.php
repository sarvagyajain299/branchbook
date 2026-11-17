<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::HqAdmin,   // HQ staff have no branch, so the default needs no extra data
            'branch_id' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function hqAdmin(): static
    {
        return $this->state(fn (): array => ['role' => Role::HqAdmin, 'branch_id' => null]);
    }

    public function forBranch(Role $role, ?Branch $branch = null): static
    {
        return $this->state(fn (): array => [
            'role' => $role,
            // A nested factory is resolved (and its row created) by Laravel when the user is created.
            'branch_id' => $branch instanceof Branch ? $branch->id : Branch::factory(),
        ]);
    }
}
