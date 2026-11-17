<?php

declare(strict_types=1);

use App\Actions\Maintenance\AnonymiseInactiveCustomers;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\OtpCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    $this->travelTo(nowAt('2026-12-01 06:00:00'));
    $this->branch = openBranch();
    $this->service = offering(30);
    $this->advisor = advisorFor($this->branch, $this->service);
});

/** A customer whose last activity was $months ago. */
function customerInactiveFor(float $months, array $extra = []): Customer
{
    return Customer::factory()->create($extra + [
        'first_name' => 'Real', 'last_name' => 'Person', 'email' => 'real.person'.random_int(1, 999999).'@example.com',
        'phone' => '+971501234567', 'marketing_opt_in' => true,
        'last_activity_at' => CarbonImmutable::now()->subDays((int) round($months * 30.44)),
    ]);
}

it('anonymises customers inactive for 24 months and keeps their appointments', function (): void {
    $old = customerInactiveFor(25);
    $past = bookedFor($this->advisor, $this->service, '2025-10-06 09:00', AppointmentStatus::Completed);
    $past->forceFill(['customer_id' => $old->id])->save();

    expect(app(AnonymiseInactiveCustomers::class)->handle())->toBe(1);

    $fresh = $old->fresh();
    expect($fresh->first_name)->toBe('Anonymised')->and($fresh->last_name)->toBe('Customer')
        ->and($fresh->email)->toBe("anonymised-{$old->id}@invalid.example")
        ->and($fresh->phone)->toBe('+000000000000')
        ->and($fresh->marketing_opt_in)->toBeFalse()->and($fresh->email_verified_at)->toBeNull()
        ->and($fresh->anonymised_at)->not->toBeNull();

    // Statistics survive: the appointment is still there, linked to the (now anonymous) customer.
    expect($past->fresh()->customer_id)->toBe($old->id)->and(Appointment::query()->count())->toBe(1);

    // And the real details are gone from the database, not just hidden by the model.
    expect(DB::table('customers')->where('id', $old->id)->value('phone'))->not->toContain('501234567')
        ->and(json_encode(DB::table('customers')->get()))->not->toContain('real.person')->not->toContain('Real')->not->toContain('Person');
});

it('leaves recently active customers alone, including just inside the limit', function (): void {
    $recent = customerInactiveFor(23);
    $nearly = customerInactiveFor(23.9);

    expect(app(AnonymiseInactiveCustomers::class)->handle())->toBe(0)
        ->and($recent->fresh()->first_name)->toBe('Real')->and($nearly->fresh()->anonymised_at)->toBeNull();
});

it('uses the sign-up date when a customer never had any activity', function (): void {
    $neverActive = Customer::factory()->create(['last_activity_at' => null, 'created_at' => CarbonImmutable::now()->subMonths(30)]);
    $newNeverActive = Customer::factory()->create(['last_activity_at' => null, 'created_at' => CarbonImmutable::now()->subMonths(2)]);

    app(AnonymiseInactiveCustomers::class)->handle();

    expect($neverActive->fresh()->anonymised_at)->not->toBeNull()->and($newNeverActive->fresh()->anonymised_at)->toBeNull();
});

it('never anonymises a customer who still has an upcoming active appointment', function (): void {
    $waiting = customerInactiveFor(40);
    bookedFor($this->advisor, $this->service, '2026-12-07 09:00', AppointmentStatus::Confirmed)->forceFill(['customer_id' => $waiting->id])->save();
    $cancelledOnly = customerInactiveFor(40);
    bookedFor($this->advisor, $this->service, '2026-12-07 10:00', AppointmentStatus::Cancelled)->forceFill(['customer_id' => $cancelledOnly->id])->save();

    expect(app(AnonymiseInactiveCustomers::class)->handle())->toBe(1);

    expect($waiting->fresh()->anonymised_at)->toBeNull()->and($waiting->fresh()->first_name)->toBe('Real')
        ->and($cancelledOnly->fresh()->anonymised_at)->not->toBeNull();
});

it('does nothing in dry-run mode but reports the count', function (): void {
    $old = customerInactiveFor(30);

    expect(app(AnonymiseInactiveCustomers::class)->handle(dryRun: true))->toBe(1);

    expect($old->fresh()->first_name)->toBe('Real')->and($old->fresh()->anonymised_at)->toBeNull()
        ->and(AuditLog::query()->count())->toBe(0);
});

it('is idempotent: a second run finds nothing', function (): void {
    customerInactiveFor(30);
    customerInactiveFor(31);

    expect(app(AnonymiseInactiveCustomers::class)->handle())->toBe(2)
        ->and(app(AnonymiseInactiveCustomers::class)->handle())->toBe(0);
});

it('deletes the customer\'s verification codes and writes an audit entry without personal data', function (): void {
    $old = customerInactiveFor(30, ['email' => 'old.timer@example.com']);
    OtpCode::query()->create(['email' => 'old.timer@example.com', 'purpose' => 'booking:NWB-AAAA-BBBB', 'code_hash' => 'x', 'expires_at' => now()->addMinutes(10)]);
    OtpCode::query()->create(['email' => 'someone.else@example.com', 'purpose' => 'booking:NWB-CCCC-DDDD', 'code_hash' => 'x', 'expires_at' => now()->addMinutes(10)]);

    app(AnonymiseInactiveCustomers::class)->handle();

    expect(OtpCode::query()->pluck('email')->all())->toBe(['someone.else@example.com']);

    $log = AuditLog::query()->where('action', 'customers.anonymised')->firstOrFail();
    expect($log->changes)->toBe(['count' => 1, 'inactive_months' => 24])
        ->and($log->actor_id)->toBeNull()
        ->and(json_encode($log->toArray()))->not->toContain('old.timer');
});

it('honours a custom number of months', function (): void {
    $eighteen = customerInactiveFor(18);

    expect(app(AnonymiseInactiveCustomers::class)->handle(24))->toBe(0)
        ->and(app(AnonymiseInactiveCustomers::class)->handle(12))->toBe(1)
        ->and($eighteen->fresh()->anonymised_at)->not->toBeNull();
});

it('works through the artisan command, including dry-run, and is scheduled daily', function (): void {
    $old = customerInactiveFor(30);

    $this->artisan('branchbook:anonymise-customers --dry-run')->expectsOutputToContain('Dry run: 1 customer(s)')->assertSuccessful();
    expect($old->fresh()->first_name)->toBe('Real');

    $this->artisan('branchbook:anonymise-customers')->expectsOutputToContain('Anonymised 1 customer(s)')->assertSuccessful();
    expect($old->fresh()->first_name)->toBe('Anonymised');

    $this->artisan('schedule:list')->expectsOutputToContain('branchbook:anonymise-customers')->assertSuccessful();
});

// ---- security log channel -------------------------------------------------------------------
it('writes failed logins and OTP lockouts to the security log without personal data', function (): void {
    $path = storage_path('logs/security-test.log');
    @unlink($path);
    config(['logging.channels.security' => ['driver' => 'single', 'path' => $path, 'level' => 'info']]);
    Log::forgetChannel('security');

    $this->post('/login', ['email' => 'Someone@Example.com', 'password' => 'wrong']);

    $log = (string) @file_get_contents($path);
    expect($log)->toContain('Failed staff login')->toContain(hash('sha256', 'someone@example.com'))
        ->not->toContain('Someone@Example.com')->not->toContain('someone@example.com');

    @unlink($path);
});
