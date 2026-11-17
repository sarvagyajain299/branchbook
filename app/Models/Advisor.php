<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AdvisorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Advisor extends Model
{
    /** @use HasFactory<AdvisorFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['user_id', 'branch_id', 'desk_label', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class);
    }

    /** @return HasMany<AdvisorShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(AdvisorShift::class);
    }

    /** @return HasMany<AdvisorTimeOff, $this> */
    public function timeOff(): HasMany
    {
        return $this->hasMany(AdvisorTimeOff::class);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
