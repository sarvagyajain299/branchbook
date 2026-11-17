<?php

declare(strict_types=1);

namespace App\Services\Availability;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Caches computed availability per (branch, service, date) for a short time.
 *
 * Invalidation uses a per-(branch, date) VERSION number that is part of every cache key.
 * Bumping the version makes all old keys unreachable at once. This works on every cache
 * driver; Cache tags would be the alternative but the database driver doesn't support them.
 */
final class AvailabilityCache
{
    /**
     * @param  Closure(): list<array{start: int, end: int, advisors: list<int>}>  $compute
     * @return list<array{start: int, end: int, advisors: list<int>}>
     */
    public function remember(int $branchId, int $serviceId, string $date, Closure $compute): array
    {
        $key = sprintf('availability:%d:%d:%s:v%d', $branchId, $serviceId, $date, $this->version($branchId, $date));

        /** @var list<array{start: int, end: int, advisors: list<int>}> */
        return Cache::remember($key, (int) config('branchbook.availability_cache_seconds'), $compute);
    }

    /** Make every cached result for this branch and date stale. */
    public function forget(int $branchId, string $date): void
    {
        Cache::forever($this->versionKey($branchId, $date), $this->version($branchId, $date) + 1);
    }

    private function version(int $branchId, string $date): int
    {
        return (int) Cache::get($this->versionKey($branchId, $date), 0);
    }

    private function versionKey(int $branchId, string $date): string
    {
        return "availability:version:{$branchId}:{$date}";
    }
}
