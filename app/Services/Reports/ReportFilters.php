<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * The date range and branch a report covers. Dates are branch-local (Dubai) calendar days;
 * the UTC bounds are what the SQL compares against, because the database stores UTC.
 */
final readonly class ReportFilters
{
    public CarbonImmutable $fromUtc;

    /** Exclusive: midnight AFTER the last local day, in UTC. */
    public CarbonImmutable $toUtcExclusive;

    public function __construct(
        public string $from,
        public string $to,
        public ?int $branchId = null,
    ) {
        $tz = (string) config('app.display_timezone');

        $this->fromUtc = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $this->toUtcExclusive = CarbonImmutable::parse($to, $tz)->startOfDay()->addDay()->utc();
    }
}
