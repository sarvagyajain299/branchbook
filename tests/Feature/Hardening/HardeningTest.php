<?php

declare(strict_types=1);

use App\Actions\Booking\CancelBooking;
use App\Actions\Queue\CallNextTicket;
use App\Actions\Queue\CheckInAppointment;
use App\Actions\Queue\IssueWalkInTicket;
use App\Actions\Queue\ProgressTicket;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->travelTo(nowAt('2026-12-07 06:00:00'));   // Mon 10:00 Dubai
    $this->branch = openBranch();
    $this->service = Service::factory()->create(['queue_prefix' => 'A', 'duration_minutes' => 30]);
    $this->advisor = advisorFor($this->branch, $this->service);
});

// ---- security headers -----------------------------------------------------------------------
it('adds security headers to every page', function (): void {
    $this->get('/')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy')
        ->assertHeaderMissing('Strict-Transport-Security');

    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
    expect($csp)->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")->toContain("form-action 'self'");
});

it('keeps the stricter no-referrer policy on the private manage page', function (): void {
    $appointment = bookedFor($this->advisor, $this->service, MON.' 09:00');
    $appointment->forceFill(['manage_token_hash' => hash('sha256', 'tok')])->save();

    $this->get(route('booking.show', [$appointment->reference, 'token' => 'tok']))->assertHeader('Referrer-Policy', 'no-referrer');
});

it('sends HSTS only over HTTPS and only when enabled', function (): void {
    config(['branchbook.hsts' => true]);

    $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');

    config(['branchbook.hsts' => false]);
    $this->get('https://localhost/')->assertHeaderMissing('Strict-Transport-Security');
});

it('marks the session cookie HttpOnly with SameSite', function (): void {
    expect(config('session.http_only'))->toBeTrue()->and(config('session.same_site'))->toBe('lax');
});

// ---- audit log ------------------------------------------------------------------------------
it('audits queue and booking actions with actor, subject and no personal data', function (): void {
    $desk = User::factory()->forBranch(Role::FrontDesk, $this->branch)->create();
    $appointment = bookedFor($this->advisor, $this->service, MON.' 09:00');
    $appointment->customer->forceFill(['email' => 'secret.person@example.com', 'first_name' => 'Secret'])->save();

    $this->actingAs($desk);
    app(CheckInAppointment::class)->handle($appointment);
    app(IssueWalkInTicket::class)->handle($this->branch, $this->service, 'Walk Inn Name');

    $this->actingAs($this->advisor->user);
    $ticket = app(CallNextTicket::class)->handle($this->advisor->user);
    app(ProgressTicket::class)->start($ticket, $this->advisor->user);
    app(ProgressTicket::class)->complete($ticket, $this->advisor->user);

    expect(AuditLog::query()->orderBy('id')->pluck('action')->all())->toBe([
        'appointment.checked_in', 'ticket.walk_in_issued', 'ticket.called', 'ticket.started', 'ticket.completed',
    ]);

    $checkIn = AuditLog::query()->where('action', 'appointment.checked_in')->firstOrFail();
    expect($checkIn->actor_id)->toBe($desk->id)->and($checkIn->subject_type)->toBe('Appointment')
        ->and($checkIn->subject_id)->toBe($appointment->id)
        ->and($checkIn->changes)->toBe(['status' => ['confirmed', 'checked_in'], 'ticket' => 'A-001']);

    $everything = AuditLog::query()->get()->map(fn (AuditLog $l): string => json_encode($l->toArray()))->implode(' ');
    expect($everything)->not->toContain('secret.person')->not->toContain('Secret')->not->toContain('Walk Inn Name');
});

it('stores the IP only as a keyed hash', function (): void {
    $this->actingAs(User::factory()->forBranch(Role::FrontDesk, $this->branch)->create());
    app(IssueWalkInTicket::class)->handle($this->branch, $this->service);

    $hash = (string) AuditLog::query()->firstOrFail()->ip_hash;

    expect($hash)->toHaveLength(64)->not->toContain('127.0.0.1');
});

it('audits staff logins and failed logins without storing the attempted email', function (): void {
    $user = User::factory()->forBranch(Role::FrontDesk, $this->branch)->create(['email' => 'desk.person@northwind.test']);

    $this->post('/login', ['email' => 'desk.person@northwind.test', 'password' => 'wrong']);
    $this->post('/login', ['email' => 'desk.person@northwind.test', 'password' => 'password']);

    $failed = AuditLog::query()->where('action', 'auth.failed')->firstOrFail();
    $login = AuditLog::query()->where('action', 'auth.login')->firstOrFail();

    expect($failed->changes)->toBe(['email_hash' => hash('sha256', 'desk.person@northwind.test')])
        ->and(json_encode($failed->toArray()))->not->toContain('desk.person')
        ->and($login->actor_id)->toBe($user->id);
});

it('audits a customer cancellation', function (): void {
    $appointment = bookedFor($this->advisor, $this->service, MON.' 13:00');   // 3 h away: outside the 2 h cut-off

    app(CancelBooking::class)->handle($appointment, 'Plans changed');

    $log = AuditLog::query()->where('action', 'appointment.cancelled')->firstOrFail();
    expect($log->actor_id)->toBeNull()->and($log->changes)->toBe(['by' => 'customer', 'reason' => 'Plans changed']);
});

// ---- health command -------------------------------------------------------------------------
it('reports healthy when the scheduler heartbeat is fresh', function (): void {
    Cache::put('scheduler:heartbeat', time(), 600);

    $this->artisan('branchbook:health')->expectsOutputToContain('Healthy.')->assertSuccessful();
});

it('reports unhealthy when the scheduler never ran or is stale', function (): void {
    expect(Artisan::call('branchbook:health'))->toBe(1);
    expect(Artisan::output())->toContain('never ran')->toContain('Unhealthy.');

    Cache::put('scheduler:heartbeat', time() - 900, 600);
    expect(Artisan::call('branchbook:health'))->toBe(1);
    expect(Artisan::output())->toContain('last ran 900s ago');
});

it('warns about failed jobs and fails on a stuck queue', function (): void {
    Cache::put('scheduler:heartbeat', time(), 600);
    DB::table('failed_jobs')->insert(['uuid' => 'u-1', 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()]);

    $this->artisan('branchbook:health')->expectsOutputToContain('1 failed job')->assertSuccessful();   // a warning, not a failure

    DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time() - 1200]);
    $this->artisan('branchbook:health')->expectsOutputToContain('is the queue worker running')->assertFailed();
});

it('fails when production would use a mailer that drops mail', function (): void {
    Cache::put('scheduler:heartbeat', time(), 600);
    config(['mail.default' => 'log']);
    $this->app['env'] = 'production';

    $this->artisan('branchbook:health')->expectsOutputToContain('silently drop customer emails')->assertFailed();
});
