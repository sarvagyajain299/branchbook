<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Closure;
use App\Models\QueueTicket;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\DemoSeeder;

beforeEach(fn () => $this->seed(DemoSeeder::class));

it('seeds the documented fictional bank', function (): void {
    expect(Branch::query()->orderBy('code')->pluck('code')->all())->toBe(['AUH-COR', 'DXB-MAR', 'SHJ-MAJ'])
        ->and(Service::query()->count())->toBe(6)
        ->and(Advisor::query()->count())->toBe(12)
        ->and(Appointment::query()->count())->toBeGreaterThan(100)
        ->and(QueueTicket::query()->count())->toBeGreaterThan(50);
});

it('creates staff for every role, with branch staff tied to a branch', function (): void {
    foreach (Role::cases() as $role) {
        expect(User::query()->where('role', $role->value)->exists())->toBeTrue($role->value);
    }

    expect(User::query()->where('role', Role::HqAdmin->value)->whereNotNull('branch_id')->exists())->toBeFalse()
        ->and(User::query()->where('role', '!=', Role::HqAdmin->value)->whereNull('branch_id')->exists())->toBeFalse();
});

it('qualifies every branch to offer every service', function (): void {
    $advisors = Advisor::query()->with('services')->get();

    foreach (Branch::all() as $branch) {
        foreach (Service::all() as $service) {
            $covered = $advisors->where('branch_id', $branch->id)->contains(fn (Advisor $a): bool => $a->services->contains('id', $service->id));
            expect($covered)->toBeTrue("{$branch->code} / {$service->code}");
        }
    }
});

it('seeds public holidays for 2026 and 2027, flagging the Islamic ones to verify', function (): void {
    expect(Closure::query()->whereNull('branch_id')->whereYear('date', 2026)->exists())->toBeTrue()
        ->and(Closure::query()->whereNull('branch_id')->whereYear('date', 2027)->exists())->toBeTrue()
        ->and(Closure::query()->where('reason', 'like', 'Eid al-Fitr%verify Islamic dates%')->exists())->toBeTrue();
});

it('seeds appointments only inside UAE banking hours, converted correctly from UTC', function (): void {
    foreach (Appointment::query()->get() as $appointment) {
        $local = $appointment->starts_at->setTimezone('Asia/Dubai');

        expect($local->dayOfWeekIso)->toBeLessThanOrEqual(5, $appointment->reference)
            ->and($local->hour)->toBeGreaterThanOrEqual(8)->toBeLessThan(15);
    }
});

it('never double-books an advisor: the generated column unique index held for every seeded row', function (): void {
    $duplicates = Appointment::query()->active()
        ->selectRaw('advisor_id, starts_at, COUNT(*) AS n')
        ->groupBy('advisor_id', 'starts_at')->havingRaw('COUNT(*) > 1')->get();

    expect($duplicates)->toBeEmpty();
});

it('numbers queue tickets uniquely per branch, day and prefix', function (): void {
    $duplicates = QueueTicket::query()
        ->selectRaw('branch_id, service_date, queue_prefix, sequence, COUNT(*) AS n')
        ->groupBy('branch_id', 'service_date', 'queue_prefix', 'sequence')->havingRaw('COUNT(*) > 1')->get();

    expect($duplicates)->toBeEmpty()
        ->and(QueueTicket::query()->where('display_code', 'A-001')->exists())->toBeTrue();
});
