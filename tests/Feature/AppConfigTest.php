<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

// Lives in tests/Feature because it needs the booted app (config, Date facade),
// even though it never touches the database.

it('stores and computes time in UTC', function (): void {
    expect(config('app.timezone'))->toBe('UTC');
});

it('displays time in Asia/Dubai', function (): void {
    expect(config('app.display_timezone'))->toBe('Asia/Dubai');
});

it('creates immutable dates everywhere', function (): void {
    expect(now())->toBeInstanceOf(CarbonImmutable::class)
        ->and(Date::parse('2026-01-05 08:00'))->toBeInstanceOf(CarbonImmutable::class);
});

it('converts a UTC instant to Dubai local time (UTC+4) at the edge', function (): void {
    $utc = CarbonImmutable::parse('2026-01-05 04:00:00', 'UTC');

    $local = $utc->setTimezone(config('app.display_timezone'));

    expect($local->format('Y-m-d H:i'))->toBe('2026-01-05 08:00')
        ->and($utc->format('H:i'))->toBe('04:00'); // original untouched: immutable
});
