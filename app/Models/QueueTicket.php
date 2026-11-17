<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransition;
use Database\Factories\QueueTicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property TicketStatus $status
 */
class QueueTicket extends Model
{
    /** @use HasFactory<QueueTicketFactory> */
    use HasFactory;

    // `status` is changed only through transitionTo().
    /** @var list<string> */
    protected $fillable = [
        'branch_id', 'service_date', 'service_id', 'queue_prefix', 'sequence', 'display_code',
        'appointment_id', 'issued_at', 'called_at', 'served_at', 'completed_at',
        'advisor_id', 'desk_label',
    ];

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'service_date' => 'immutable_date',
            'sequence' => 'integer',
            'status' => TicketStatus::class,
            'issued_at' => 'immutable_datetime',
            'called_at' => 'immutable_datetime',
            'served_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @throws InvalidStatusTransition */
    public function transitionTo(TicketStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidStatusTransition::between($this->status, $next);
        }

        $this->status = $next;
        $this->save();
    }

    /**
     * Tickets this staff member may see: HQ all, everyone else their branch's queue.
     *
     * @param  Builder<QueueTicket>  $query
     * @return Builder<QueueTicket>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isHq() ? $query : $query->where('branch_id', $user->branch_id);
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

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<Advisor, $this> */
    public function advisor(): BelongsTo
    {
        return $this->belongsTo(Advisor::class);
    }
}
