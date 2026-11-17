<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Models\Advisor;
use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Books [advisor, start] directly in the database with the given status. */
function book(Advisor $advisor, AppointmentStatus $status, string $start = '2026-12-07 05:00:00'): Appointment
{
    $startsAt = CarbonImmutable::parse($start, 'UTC');

    return Appointment::factory()->state([
        'advisor_id' => $advisor->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes(30),
        'status' => $status,
    ])->create();
}

it('rejects a second ACTIVE appointment for the same advisor and start time', function (AppointmentStatus $first, AppointmentStatus $second): void {
    $advisor = Advisor::factory()->create();
    book($advisor, $first);

    expect(fn () => book($advisor, $second))->toThrow(UniqueConstraintViolationException::class);
})->with([
    'confirmed then held' => [AppointmentStatus::Confirmed, AppointmentStatus::Held],
    'held then confirmed' => [AppointmentStatus::Held, AppointmentStatus::Confirmed],
    'checked in then in service' => [AppointmentStatus::CheckedIn, AppointmentStatus::InService],
]);

it('allows an active appointment next to a cancelled, expired, no-show or completed one', function (AppointmentStatus $inactive): void {
    $advisor = Advisor::factory()->create();
    book($advisor, $inactive);
    book($advisor, AppointmentStatus::Confirmed);

    expect(Appointment::query()->where('advisor_id', $advisor->id)->count())->toBe(2);
})->with([
    AppointmentStatus::Cancelled,
    AppointmentStatus::Expired,
    AppointmentStatus::NoShow,
    AppointmentStatus::Completed,
]);

it('allows any number of cancelled appointments in the same slot', function (): void {
    $advisor = Advisor::factory()->create();

    book($advisor, AppointmentStatus::Cancelled);
    book($advisor, AppointmentStatus::Cancelled);
    book($advisor, AppointmentStatus::Expired);

    expect(Appointment::query()->where('advisor_id', $advisor->id)->count())->toBe(3);
});

it('allows the same time for different advisors, and different times for the same advisor', function (): void {
    $one = Advisor::factory()->create();
    $two = Advisor::factory()->create();

    book($one, AppointmentStatus::Confirmed, '2026-12-07 05:00:00');
    book($two, AppointmentStatus::Confirmed, '2026-12-07 05:00:00');
    book($one, AppointmentStatus::Confirmed, '2026-12-07 06:30:00');

    expect(Appointment::query()->active()->count())->toBe(3);
});

it('frees the slot when an active appointment is cancelled', function (): void {
    $advisor = Advisor::factory()->create();
    $first = book($advisor, AppointmentStatus::Confirmed);

    $first->transitionTo(AppointmentStatus::Cancelled);

    expect(book($advisor, AppointmentStatus::Confirmed)->exists)->toBeTrue();
});

it('keeps the migration status list in sync with the AppointmentStatus enum', function (): void {
    $expression = (string) DB::table('information_schema.COLUMNS')
        ->where('TABLE_SCHEMA', DB::getDatabaseName())
        ->where('TABLE_NAME', 'appointments')
        ->where('COLUMN_NAME', 'active_slot_key')
        ->value('GENERATION_EXPRESSION');

    // MySQL stores the list as: in (_utf8mb4\'held\',_utf8mb4\'confirmed\', ...). Strip the
    // charset prefixes and quotes, then pull out the status words.
    preg_match('/in \(([^)]*)\)/i', $expression, $m);
    $list = str_replace(['_utf8mb4', '\\', "'"], ' ', $m[1] ?? '');
    preg_match_all('/[a-z]+(?:_[a-z]+)*/', $list, $values);

    $inDatabase = $values[1];
    $inEnum = AppointmentStatus::activeValues();
    sort($inDatabase);
    sort($inEnum);

    expect($inDatabase)->toBe($inEnum);
});
