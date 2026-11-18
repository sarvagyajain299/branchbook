<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Mask;
use Backpack\CRUD\app\Models\Traits\CrudTrait;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank customer. Has no password and never logs in; proves email ownership with a
 * one-time code. Holds NO Emirates ID, account or card numbers, by design.
 */
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use CrudTrait, HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'first_name', 'last_name', 'email', 'phone', 'email_verified_at',
        'marketing_opt_in', 'last_activity_at',
    ];

    // anonymised_at is set only by the retention job (forceFill), never mass-assigned.

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Encrypted at rest with APP_KEY. Trade-off: the ciphertext differs on every write,
            // so you can't WHERE phone = ? or index it. Look customers up by email instead.
            'phone' => 'encrypted',
            'email_verified_at' => 'immutable_datetime',
            'last_activity_at' => 'immutable_datetime',
            'marketing_opt_in' => 'boolean',
        ];
    }

    public function fullName(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    public function maskedEmail(): string
    {
        return Mask::email($this->email);
    }

    public function maskedPhone(): string
    {
        return Mask::phone($this->phone);
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
