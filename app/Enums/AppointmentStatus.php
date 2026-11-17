<?php

declare(strict_types=1);

namespace App\Enums;

enum AppointmentStatus: string
{
    case Held = 'held';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case InService = 'in_service';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Held => 'Held (awaiting verification)',
            self::Confirmed => 'Confirmed',
            self::CheckedIn => 'Checked in',
            self::InService => 'In service',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
            self::Expired => 'Expired',
        };
    }

    /** An "active" appointment occupies its advisor's time slot. */
    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /**
     * The single source of truth for the allowed status transitions.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Held => [self::Confirmed, self::Expired, self::Cancelled],
            self::Confirmed => [self::CheckedIn, self::Cancelled, self::NoShow],
            self::CheckedIn => [self::InService, self::Cancelled, self::NoShow],
            self::InService => [self::Completed],
            self::Completed, self::Cancelled, self::NoShow, self::Expired => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /**
     * Statuses that block an advisor's slot.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Held, self::Confirmed, self::CheckedIn, self::InService];
    }

    /**
     * Same list as strings, for queries.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::active());
    }
}
