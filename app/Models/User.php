<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Backpack\CRUD\app\Models\Traits\CrudTrait;
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
 * @property \Carbon\CarbonInterface|null $email_verified_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use CrudTrait, HasApiTokens, HasFactory, Notifiable;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /**
     * The admin panel's avatar: a coloured badge with the person's initials, drawn as an inline SVG.
     * No request ever leaves the server (Gravatar would receive a hash of the staff email).
     */
    public function avatarUrl(): string
    {
        $initials = collect(preg_split('/\s+/', trim($this->name)) ?: [])
            ->filter()->take(2)->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
        $initials = htmlspecialchars($initials === '' ? '?' : $initials, ENT_QUOTES);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" rx="40" fill="#0f766e"/>'
            .'<text x="50%" y="54%" font-family="Arial, sans-serif" font-size="32" fill="#fff" text-anchor="middle" dominant-baseline="middle">'.$initials.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
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
