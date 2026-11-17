<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AppointmentChannel;
use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Events\AppointmentChanged;
use App\Exceptions\InvalidStatusTransition;
use Carbon\CarbonImmutable;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property AppointmentStatus $status
 * @property AppointmentChannel $channel
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $hold_expires_at
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    // `status` is deliberately NOT fillable: it may only change through transitionTo(),
    // so an invalid jump (e.g. completed -> held) can't slip in via mass assignment.
    // `active_slot_key` is a MySQL generated column and is never written by PHP.
    /** @var list<string> */
    protected $fillable = [
        'reference', 'customer_id', 'branch_id', 'service_id', 'advisor_id', 'starts_at', 'ends_at',
        'channel', 'hold_expires_at', 'manage_token_hash', 'cancelled_reason',
        'rescheduled_from_id', 'notes',
    ];

    /** @var list<string> */
    protected $hidden = ['manage_token_hash'];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'hold_expires_at' => 'immutable_datetime',
            'status' => AppointmentStatus::class,
            'channel' => AppointmentChannel::class,
        ];
    }

    /** Local date the appointment had before a reschedule; used to clear the old day's cache too. */
    private ?string $previousSlotDate = null;

    protected static function booted(): void
    {
        static::updating(static function (self $appointment): void {
            if ($appointment->isDirty('starts_at')) {
                $appointment->previousSlotDate = self::localDate($appointment->getOriginal('starts_at'));
            }
        });

        $announce = static function (self $appointment): void {
            $dates = [self::localDate($appointment->starts_at)];

            if ($appointment->previousSlotDate !== null) {
                $dates[] = $appointment->previousSlotDate;
                $appointment->previousSlotDate = null;
            }

            AppointmentChanged::dispatch($appointment->branch_id, $dates);
        };

        static::saved($announce);
        static::deleted($announce);
    }

    private static function localDate(mixed $instant): string
    {
        return CarbonImmutable::parse($instant)->setTimezone((string) config('app.display_timezone'))->toDateString();
    }

    /**
     * The one place a status changes. Rejects transitions the state machine doesn't allow.
     *
     * @throws InvalidStatusTransition
     */
    public function transitionTo(AppointmentStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidStatusTransition::between($this->status, $next);
        }

        $this->status = $next;
        $this->save();
    }

    /**
     * Appointments this staff member may see.
     * HQ: all. Manager and front desk: their branch. Advisor: only their own.
     *
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->isHq() => $query,
            $user->role === Role::Advisor => $query->whereIn(
                'advisor_id',
                Advisor::query()->where('user_id', $user->id)->select('id'),
            ),
            default => $query->where('branch_id', $user->branch_id),
        };
    }

    /**
     * Appointments that currently block their advisor's calendar.
     *
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AppointmentStatus::activeValues());
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return BelongsTo<Advisor, $this> */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(Advisor::class);
    }

    /** @return BelongsTo<Appointment, $this> */
    public function rescheduledFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rescheduled_from_id');
    }

    /** @return HasOne<QueueTicket, $this> */
    public function queueTicket(): HasOne
    {
        return $this->hasOne(QueueTicket::class);
    }
}
