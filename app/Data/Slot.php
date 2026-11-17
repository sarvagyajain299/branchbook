<?php

declare(strict_types=1);

namespace App\Data;

use Carbon\CarbonImmutable;

/** One bookable time slot. Instants are UTC; convert to the display timezone at the edges. */
final readonly class Slot
{
    /** @param list<int> $advisorIds ids of the qualified advisors who are free for this slot */
    public function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $freeAdvisors,
        public array $advisorIds,
    ) {}

    /** @param array{start: int, end: int, advisors: list<int>} $raw */
    public static function fromRaw(array $raw): self
    {
        return new self(
            CarbonImmutable::createFromTimestampUTC($raw['start']),
            CarbonImmutable::createFromTimestampUTC($raw['end']),
            count($raw['advisors']),
            $raw['advisors'],
        );
    }

    /** @return array{starts_at: string, ends_at: string, local_time: string, free_advisors: int} */
    public function toArray(): array
    {
        $tz = (string) config('app.display_timezone');

        return [
            'starts_at' => $this->startsAt->toIso8601ZuluString(),
            'ends_at' => $this->endsAt->toIso8601ZuluString(),
            'local_time' => $this->startsAt->setTimezone($tz)->format('H:i'),
            'free_advisors' => $this->freeAdvisors,
        ];
    }
}
