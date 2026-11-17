<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus as S;
use App\Enums\TicketStatus as T;

// The allowed transitions, written out independently of the enum so a change to the
// enum's rules has to be made here too, on purpose.
$appointmentRules = [
    'held' => ['confirmed', 'expired', 'cancelled'],
    'confirmed' => ['checked_in', 'cancelled', 'no_show'],
    'checked_in' => ['in_service', 'cancelled', 'no_show'],
    'in_service' => ['completed'],
    'completed' => [],
    'cancelled' => [],
    'no_show' => [],
    'expired' => [],
];

it('allows exactly the documented appointment transitions (all 64 pairs)', function () use ($appointmentRules): void {
    foreach (S::cases() as $from) {
        foreach (S::cases() as $to) {
            expect($from->canTransitionTo($to))
                ->toBe(in_array($to->value, $appointmentRules[$from->value], true), "{$from->value} -> {$to->value}");
        }
    }
});

it('treats completed, cancelled, no-show and expired as terminal', function (): void {
    foreach ([S::Completed, S::Cancelled, S::NoShow, S::Expired] as $terminal) {
        expect($terminal->allowedTransitions())->toBeEmpty();
    }
});

it('knows which statuses block an advisor slot', function (): void {
    expect(S::activeValues())->toBe(['held', 'confirmed', 'checked_in', 'in_service'])
        ->and(S::Held->isActive())->toBeTrue()
        ->and(S::Cancelled->isActive())->toBeFalse()
        ->and(S::Expired->isActive())->toBeFalse();
});

it('has a label for every status', function (): void {
    foreach (S::cases() as $status) {
        expect($status->label())->not->toBeEmpty();
    }
});

it('allows the documented queue ticket transitions', function (): void {
    expect(T::Waiting->canTransitionTo(T::Called))->toBeTrue()
        ->and(T::Called->canTransitionTo(T::Serving))->toBeTrue()
        ->and(T::Called->canTransitionTo(T::NoShow))->toBeTrue()
        ->and(T::Serving->canTransitionTo(T::Completed))->toBeTrue()
        ->and(T::Waiting->canTransitionTo(T::Serving))->toBeFalse()
        ->and(T::Completed->canTransitionTo(T::Waiting))->toBeFalse()
        ->and(T::NoShow->canTransitionTo(T::Called))->toBeFalse();
});
