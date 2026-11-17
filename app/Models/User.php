<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A member of Northwind Bank staff. Customers are a separate model and never log in.
 *
 * Staff must verify their email before reaching any authenticated page
 * (the "verified" middleware), so a mistyped address can't receive bank access.
 *
 * `role` and `branch_id` are NOT mass-assignable: a form that posts role=hq_admin
 * must never be able to promote a user. They are set explicitly by admin code.
 *
 * @property Role $role
 * @property int|null $branch_id
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function isHq(): bool
    {
        return $this->role === Role::HqAdmin;
    }

    /** HQ may touch any branch; everyone else only their own. */
    public function canAccessBranch(int $branchId): bool
    {
        return $this->isHq() || $this->branch_id === $branchId;
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasOne<Advisor, $this> */
    public function advisor(): HasOne
    {
        return $this->hasOne(Advisor::class);
    }
}
