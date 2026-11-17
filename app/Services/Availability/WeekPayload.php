<?php

declare(strict_types=1);

namespace App\Services\Availability;

use App\Data\Slot;
use App\Models\Branch;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Closure;

/** Builds the JSON the slot-picker script renders: a run of days, each with its slots and a link per slot. */
final class WeekPayload
{
    public function __construct(private readonly AvailabilityService $availability) {}

    /**
     * @param  Closure(Slot): string  $urlFor  where choosing a slot should lead
     * @return array{from: string, days: list<array<string, mixed>>}
     */
    public function build(Branch $branch, Service $service, ?string $from, int $days, Closure $urlFor): array
    {
        $tz = (string) config('app.display_timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $start = $from === null ? $today : CarbonImmutable::parse($from, $tz)->startOfDay();
        $start = $start->lessThan($today) ? $today : $start;

        $result = [];
        foreach ($this->availability->slotsForRange($branch, $service, $start->toDateString(), $days) as $date => $slots) {
            $result[] = [
                'date' => $date,
                'label' => CarbonImmutable::parse($date, $tz)->format('D j M'),
                'slots' => array_map(fn (Slot $slot): array => $slot->toArray() + ['url' => $urlFor($slot)], $slots),
            ];
        }

        return ['from' => $start->toDateString(), 'days' => $result];
    }
}
