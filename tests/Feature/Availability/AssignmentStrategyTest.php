<?php

declare(strict_types=1);

use App\Models\Advisor;
use App\Models\Appointment;
use App\Services\Assignment\AdvisorAssignmentStrategy;
use App\Services\Assignment\LeastBookedTodayStrategy;
use App\Services\Assignment\RandomStrategy;
use Carbon\CarbonImmutable;

function dayAt(string $local): CarbonImmutable
{
    return CarbonImmutable::parse($local, 'Asia/Dubai')->utc();
}

function bookOn(Advisor $advisor, string $local): void
{
    $start = dayAt($local);
    Appointment::factory()->state([
        'advisor_id' => $advisor->id, 'branch_id' => $advisor->branch_id,
        'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
    ])->create();
}

it('picks the advisor with the fewest bookings that day', function (): void {
    $busy = Advisor::factory()->create();
    $quiet = Advisor::factory()->create();
    bookOn($busy, '2026-12-07 08:00');
    bookOn($busy, '2026-12-07 09:00');
    bookOn($quiet, '2026-12-07 08:00');

    $chosen = (new LeastBookedTodayStrategy)->choose(collect([$busy, $quiet]), dayAt('2026-12-07 10:00'));

    expect($chosen?->is($quiet))->toBeTrue();
});

it('counts only the same local day, not other days', function (): void {
    $a = Advisor::factory()->create();
    $b = Advisor::factory()->create();
    bookOn($a, '2026-12-08 09:00');   // tomorrow: must not count against $a today
    bookOn($a, '2026-12-08 10:00');
    bookOn($b, '2026-12-07 09:00');

    $chosen = (new LeastBookedTodayStrategy)->choose(collect([$a, $b]), dayAt('2026-12-07 10:00'));

    expect($chosen?->is($a))->toBeTrue();
});

it('breaks ties with the lowest advisor id, so the result is predictable', function (): void {
    $first = Advisor::factory()->create();
    $second = Advisor::factory()->create();

    $chosen = (new LeastBookedTodayStrategy)->choose(collect([$second, $first]), dayAt('2026-12-07 10:00'));

    expect($chosen?->is($first))->toBeTrue();
});

it('ignores cancelled bookings when balancing the load', function (): void {
    $a = Advisor::factory()->create();
    $b = Advisor::factory()->create();
    $start = dayAt('2026-12-07 09:00');
    Appointment::factory()->status(App\Enums\AppointmentStatus::Cancelled)->state([
        'advisor_id' => $a->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
    ])->create();
    bookOn($b, '2026-12-07 08:00');

    $chosen = (new LeastBookedTodayStrategy)->choose(collect([$a, $b]), dayAt('2026-12-07 10:00'));

    expect($chosen?->is($a))->toBeTrue();
});

it('returns null when there is nobody to choose', function (): void {
    expect((new LeastBookedTodayStrategy)->choose(collect(), dayAt('2026-12-07 10:00')))->toBeNull()
        ->and((new RandomStrategy)->choose(collect(), dayAt('2026-12-07 10:00')))->toBeNull();
});

it('random strategy always returns one of the candidates', function (): void {
    $candidates = Advisor::factory()->count(3)->create();

    for ($i = 0; $i < 20; $i++) {
        $chosen = (new RandomStrategy)->choose($candidates, dayAt('2026-12-07 10:00'));
        expect($candidates->contains('id', $chosen?->id))->toBeTrue();
    }
});

it('binds the load-balancing strategy by default and the random one when configured', function (): void {
    expect(app(AdvisorAssignmentStrategy::class))->toBeInstanceOf(LeastBookedTodayStrategy::class);

    config(['branchbook.assignment_strategy' => 'random']);

    expect(app(AdvisorAssignmentStrategy::class))->toBeInstanceOf(RandomStrategy::class);
});
