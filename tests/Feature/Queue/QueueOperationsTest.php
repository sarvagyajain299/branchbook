<?php

declare(strict_types=1);

use App\Actions\Queue\CallNextTicket;
use App\Actions\Queue\CheckInAppointment;
use App\Actions\Queue\IssueQueueTicket;
use App\Actions\Queue\ProgressTicket;
use App\Enums\AppointmentStatus;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Exceptions\QueueActionNotAllowed;
use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\QueueTicket;
use App\Models\Service;
use Illuminate\Database\UniqueConstraintViolationException;

// "Now" is Mon 2026-12-07 10:00 Dubai (06:00 UTC), a normal working day.
beforeEach(function (): void {
    $this->travelTo(nowAt('2026-12-07 06:00:00'));

    $this->branch = openBranch();
    $this->accounts = Service::factory()->create(['queue_prefix' => 'A', 'duration_minutes' => 30, 'name' => 'Accounts']);
    $this->mortgage = Service::factory()->create(['queue_prefix' => 'M', 'duration_minutes' => 30, 'name' => 'Mortgage']);
    $this->issue = app(IssueQueueTicket::class);
});

/** Issues a ticket as if at a given UTC time. */
function ticketAt(object $test, Service $service, string $utc, ?Appointment $appointment = null, ?Branch $branch = null): QueueTicket
{
    return $test->issue->handle($branch ?? $test->branch, $service, $appointment, null, nowAt($utc));
}

/** An advisor at the branch qualified for the given services. */
function qualifiedAdvisor(object $test, Service ...$services): Advisor
{
    $advisor = advisorFor($test->branch, $services[0]);
    $advisor->services()->sync(collect($services)->pluck('id'));

    return $advisor;
}

// ---------------------------------------------------------------------------------------------
// Ticket numbering
// ---------------------------------------------------------------------------------------------

it('numbers tickets per prefix, with leading zeros', function (): void {
    $codes = [
        ticketAt($this, $this->accounts, '2026-12-07 05:00:00')->display_code,
        ticketAt($this, $this->accounts, '2026-12-07 05:01:00')->display_code,
        ticketAt($this, $this->mortgage, '2026-12-07 05:02:00')->display_code,
        ticketAt($this, $this->accounts, '2026-12-07 05:03:00')->display_code,
    ];

    expect($codes)->toBe(['A-001', 'A-002', 'M-001', 'A-003']);
});

it('numbers each branch independently', function (): void {
    $other = openBranch();

    expect(ticketAt($this, $this->accounts, '2026-12-07 05:00:00')->display_code)->toBe('A-001')
        ->and(ticketAt($this, $this->accounts, '2026-12-07 05:01:00', branch: $other)->display_code)->toBe('A-001')
        ->and(ticketAt($this, $this->accounts, '2026-12-07 05:02:00')->display_code)->toBe('A-002');
});

it('restarts numbering every day', function (): void {
    ticketAt($this, $this->accounts, '2026-12-07 05:00:00');
    ticketAt($this, $this->accounts, '2026-12-07 05:01:00');

    $nextDay = ticketAt($this, $this->accounts, '2026-12-08 05:00:00');

    expect($nextDay->display_code)->toBe('A-001')
        ->and($nextDay->service_date->toDateString())->toBe('2026-12-08');
});

it('switches day at midnight BRANCH time, not UTC', function (): void {
    $beforeMidnight = ticketAt($this, $this->accounts, '2026-12-07 19:59:00');   // 23:59 Dubai, Dec 7
    $afterMidnight = ticketAt($this, $this->accounts, '2026-12-07 20:01:00');    // 00:01 Dubai, Dec 8

    expect($beforeMidnight->service_date->toDateString())->toBe('2026-12-07')
        ->and($afterMidnight->service_date->toDateString())->toBe('2026-12-08')
        ->and($afterMidnight->display_code)->toBe('A-001');
});

it('never repeats a number: 25 tickets are 1..25 and the database rejects a duplicate', function (): void {
    $sequences = collect(range(1, 25))->map(fn (int $i): int => ticketAt($this, $this->accounts, '2026-12-07 05:00:00')->sequence);

    expect($sequences->all())->toBe(range(1, 25));

    expect(fn () => QueueTicket::factory()->create([
        'branch_id' => $this->branch->id, 'service_id' => $this->accounts->id,
        'service_date' => '2026-12-07', 'queue_prefix' => 'A', 'sequence' => 7, 'display_code' => 'A-007',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('continues after existing tickets and records when each was issued', function (): void {
    QueueTicket::factory()->create([
        'branch_id' => $this->branch->id, 'service_id' => $this->accounts->id,
        'service_date' => '2026-12-07', 'queue_prefix' => 'A', 'sequence' => 41, 'display_code' => 'A-041',
    ]);

    $ticket = ticketAt($this, $this->accounts, '2026-12-07 05:30:00');

    expect($ticket->display_code)->toBe('A-042')
        ->and($ticket->status)->toBe(TicketStatus::Waiting)
        ->and($ticket->issued_at->equalTo(nowAt('2026-12-07 05:30:00')))->toBeTrue()
        ->and($ticket->called_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Check-in
// ---------------------------------------------------------------------------------------------

it('checks an appointment in and issues a ticket with the service prefix', function (): void {
    $advisor = qualifiedAdvisor($this, $this->mortgage);
    $appointment = bookedFor($advisor, $this->mortgage, MON.' 09:00');

    $ticket = app(CheckInAppointment::class)->handle($appointment);

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn)
        ->and($ticket->display_code)->toBe('M-001')
        ->and($ticket->appointment_id)->toBe($appointment->id)
        ->and($ticket->status)->toBe(TicketStatus::Waiting);
});

it('refuses a second check-in, a cancelled appointment, and an appointment for another day', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    $appointment = bookedFor($advisor, $this->accounts, MON.' 09:00');
    $checkIn = app(CheckInAppointment::class);

    $checkIn->handle($appointment);
    expect(fn () => $checkIn->handle($appointment))->toThrow(QueueActionNotAllowed::class, 'not waiting to be checked in');
    expect(QueueTicket::query()->count())->toBe(1);

    $cancelled = bookedFor($advisor, $this->accounts, MON.' 10:00', AppointmentStatus::Cancelled);
    expect(fn () => $checkIn->handle($cancelled))->toThrow(QueueActionNotAllowed::class);

    $tomorrow = bookedFor($advisor, $this->accounts, '2026-12-08 09:00');
    expect(fn () => $checkIn->handle($tomorrow))->toThrow(QueueActionNotAllowed::class, 'booked for today');
});

// ---------------------------------------------------------------------------------------------
// Call next: priority, skills, concurrency
// ---------------------------------------------------------------------------------------------

it('calls a due appointment before an earlier walk-in', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    ticketAt($this, $this->accounts, '2026-12-07 04:50:00');                                  // walk-in, waiting longest
    $appointment = bookedFor($advisor, $this->accounts, MON.' 09:00');                         // due: 09:00 < now (10:00)
    app(CheckInAppointment::class)->handle($appointment, nowAt('2026-12-07 05:05:00'));

    $called = app(CallNextTicket::class)->handle($advisor->user);

    expect($called?->appointment_id)->toBe($appointment->id);
});

it('calls appointment tickets in appointment-time order, not check-in order', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    $late = bookedFor($advisor, $this->accounts, MON.' 09:30');
    $early = bookedFor($advisor, $this->accounts, MON.' 09:00');
    app(CheckInAppointment::class)->handle($late, nowAt('2026-12-07 05:20:00'));    // arrived first...
    app(CheckInAppointment::class)->handle($early, nowAt('2026-12-07 05:25:00'));   // ...but booked later in the day

    expect(app(CallNextTicket::class)->handle($advisor->user)?->appointment_id)->toBe($early->id);
});

it('skips an appointment whose time has not arrived yet, and falls back to a walk-in', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    $notYet = bookedFor($advisor, $this->accounts, MON.' 12:00');                 // starts 12:00, now is 10:00
    app(CheckInAppointment::class)->handle($notYet);
    $walkIn = ticketAt($this, $this->accounts, '2026-12-07 06:00:00');

    expect(app(CallNextTicket::class)->handle($advisor->user)?->id)->toBe($walkIn->id);
});

it('returns nobody when only an early arrival is waiting', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    app(CheckInAppointment::class)->handle(bookedFor($advisor, $this->accounts, MON.' 12:00'));

    expect(app(CallNextTicket::class)->handle($advisor->user))->toBeNull();
});

it('serves walk-ins first come, first served', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    $second = ticketAt($this, $this->accounts, '2026-12-07 05:10:00');
    $first = ticketAt($this, $this->accounts, '2026-12-07 05:05:00');   // issued earlier in real time

    expect(app(CallNextTicket::class)->handle($advisor->user)?->id)->toBe($first->id);
    $first->refresh()->transitionTo(TicketStatus::NoShow);   // free the advisor
    expect(app(CallNextTicket::class)->handle($advisor->user)?->id)->toBe($second->id);
});

it('only calls tickets for services the advisor is qualified for', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);                         // accounts only
    $mortgageTicket = ticketAt($this, $this->mortgage, '2026-12-07 05:00:00');   // waiting longest, wrong skill
    $accountsTicket = ticketAt($this, $this->accounts, '2026-12-07 05:30:00');

    $called = app(CallNextTicket::class)->handle($advisor->user);

    expect($called?->id)->toBe($accountsTicket->id)
        ->and($mortgageTicket->fresh()->status)->toBe(TicketStatus::Waiting);

    $accountsTicket->refresh()->transitionTo(TicketStatus::NoShow);
    expect(app(CallNextTicket::class)->handle($advisor->user))->toBeNull();   // only the mortgage ticket is left
});

it('ignores other branches and other days', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    ticketAt($this, $this->accounts, '2026-12-07 05:00:00', branch: openBranch());   // another branch
    ticketAt($this, $this->accounts, '2026-12-06 05:00:00');                          // yesterday

    expect(app(CallNextTicket::class)->handle($advisor->user))->toBeNull();
});

it('records who, where and when on the called ticket', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    $ticket = ticketAt($this, $this->accounts, '2026-12-07 05:00:00');

    $called = app(CallNextTicket::class)->handle($advisor->user);

    expect($called?->id)->toBe($ticket->id)
        ->and($called->status)->toBe(TicketStatus::Called)
        ->and($called->advisor_id)->toBe($advisor->id)
        ->and($called->desk_label)->toBe($advisor->desk_label)
        ->and($called->called_at?->equalTo(nowAt('2026-12-07 06:00:00')))->toBeTrue();
});

it('gives two advisors pressing "Call next" two different customers', function (): void {
    // Honest note: Pest runs in ONE database transaction, so truly simultaneous requests
    // can't be reproduced here. This checks the observable guarantee (no customer called
    // twice) with back-to-back calls; the SKIP LOCKED query is what provides it under real overlap.
    $one = qualifiedAdvisor($this, $this->accounts);
    $two = qualifiedAdvisor($this, $this->accounts);
    ticketAt($this, $this->accounts, '2026-12-07 05:00:00');
    ticketAt($this, $this->accounts, '2026-12-07 05:01:00');

    $first = app(CallNextTicket::class)->handle($one->user);
    $second = app(CallNextTicket::class)->handle($two->user);
    $third = app(CallNextTicket::class)->handle($qualifiedThird = qualifiedAdvisor($this, $this->accounts)->user);

    expect($first?->id)->not->toBe($second?->id)
        ->and($first?->advisor_id)->toBe($one->id)
        ->and($second?->advisor_id)->toBe($two->id)
        ->and($third)->toBeNull();   // only two customers were waiting
});

it('does not let a busy advisor call another customer', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    ticketAt($this, $this->accounts, '2026-12-07 05:00:00');
    ticketAt($this, $this->accounts, '2026-12-07 05:01:00');

    app(CallNextTicket::class)->handle($advisor->user);

    expect(fn () => app(CallNextTicket::class)->handle($advisor->user))
        ->toThrow(QueueActionNotAllowed::class, 'Finish or mark the current customer');
});

// ---------------------------------------------------------------------------------------------
// Ticket lifecycle and timestamps
// ---------------------------------------------------------------------------------------------

it('stamps issued, called, served and completed times and moves the appointment along', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    $appointment = bookedFor($advisor, $this->accounts, MON.' 09:00');
    $progress = app(ProgressTicket::class);

    $ticket = app(CheckInAppointment::class)->handle($appointment, nowAt('2026-12-07 05:00:00'));
    $this->travelTo(nowAt('2026-12-07 06:05:00'));
    $called = app(CallNextTicket::class)->handle($advisor->user);
    $this->travelTo(nowAt('2026-12-07 06:07:00'));
    $progress->start($called, $advisor->user);
    expect($appointment->fresh()->status)->toBe(AppointmentStatus::InService);
    $this->travelTo(nowAt('2026-12-07 06:32:00'));
    $progress->complete($called, $advisor->user);

    $done = $ticket->fresh();
    expect($done->status)->toBe(TicketStatus::Completed)
        ->and($done->issued_at->format('H:i'))->toBe('05:00')
        ->and($done->called_at?->format('H:i'))->toBe('06:05')
        ->and($done->served_at?->format('H:i'))->toBe('06:07')
        ->and($done->completed_at?->format('H:i'))->toBe('06:32')
        ->and($appointment->fresh()->status)->toBe(AppointmentStatus::Completed);
});

it('marks a no-show: ticket and appointment both become no-show', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    $appointment = bookedFor($advisor, $this->accounts, MON.' 09:00');
    app(CheckInAppointment::class)->handle($appointment);
    $called = app(CallNextTicket::class)->handle($advisor->user);

    app(ProgressTicket::class)->noShow($called, $advisor->user);

    expect($called->fresh()->status)->toBe(TicketStatus::NoShow)
        ->and($appointment->fresh()->status)->toBe(AppointmentStatus::NoShow);
    // The advisor is free again.
    expect(app(CallNextTicket::class)->handle($advisor->user))->toBeNull();
});

it('recalls a called ticket without changing its status or first-call time', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    ticketAt($this, $this->accounts, '2026-12-07 05:00:00');
    $called = app(CallNextTicket::class)->handle($advisor->user);

    $this->travelTo(nowAt('2026-12-07 06:03:00'));
    app(ProgressTicket::class)->recall($called, $advisor->user);

    $fresh = $called->fresh();
    expect($fresh->status)->toBe(TicketStatus::Called)
        ->and($fresh->called_at?->format('H:i'))->toBe('06:00')
        ->and($fresh->recalled_at?->format('H:i'))->toBe('06:03');
});

it('rejects out-of-order and foreign actions on a ticket', function (): void {
    $mine = qualifiedAdvisor($this, $this->accounts);
    $other = qualifiedAdvisor($this, $this->accounts);
    ticketAt($this, $this->accounts, '2026-12-07 05:00:00');
    $called = app(CallNextTicket::class)->handle($mine->user);
    $progress = app(ProgressTicket::class);

    expect(fn () => $progress->complete($called, $mine->user))->toThrow(InvalidStatusTransition::class);    // not started yet
    expect(fn () => $progress->start($called, $other->user))->toThrow(QueueActionNotAllowed::class, 'another advisor');

    $progress->start($called, $mine->user);
    expect(fn () => $progress->noShow($called, $mine->user))->toThrow(InvalidStatusTransition::class);      // already serving
    expect(fn () => $progress->recall($called, $mine->user))->toThrow(QueueActionNotAllowed::class);        // not "called" any more
});

it('measures wait time from issue to first call', function (): void {
    $advisor = qualifiedAdvisor($this, $this->accounts);
    ticketAt($this, $this->accounts, '2026-12-07 05:40:00');

    $called = app(CallNextTicket::class)->handle($advisor->user);   // now 06:00

    expect(abs($called?->called_at?->diffInMinutes($called->issued_at) ?? 0))->toBe(20.0);
});
