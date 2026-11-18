<?php

declare(strict_types=1);

namespace App\Models;

use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use CrudTrait, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'city', 'address', 'latitude', 'longitude', 'phone', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Branches this staff member may see: HQ sees all, everyone else only their own.
     *
     * @param  Builder<Branch>  $query
     * @return Builder<Branch>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isHq() ? $query : $query->whereKey($user->branch_id);
    }

    /** @return HasMany<Advisor, $this> */
    public function advisors(): HasMany
    {
        return $this->hasMany(Advisor::class);
    }

    /** @return HasMany<BranchHour, $this> */
    public function hours(): HasMany
    {
        return $this->hasMany(BranchHour::class);
    }

    /** @return HasMany<Closure, $this> */
    public function closures(): HasMany
    {
        return $this->hasMany(Closure::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** @return HasMany<QueueTicket, $this> */
    public function queueTickets(): HasMany
    {
        return $this->hasMany(QueueTicket::class);
    }
}
