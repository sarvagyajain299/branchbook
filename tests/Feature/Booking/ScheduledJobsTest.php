<?php

declare(strict_types=1);

use App\Actions\Maintenance\MarkNoShows;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentReminder;
use App\Models\Appointment;
use App\Services\Reminders\LogChannel;
use App\Services\Reminders\ReminderChannel;
use Illuminate\Support\Facades\Log;

// "Now" is Tue 2026-12-01 10:00 Dubai (06:00 UTC).
beforeEach(function (): void {
    $this->travelTo(nowAt());

    $this->branch = openBranch();
    $this->service = offering(30);
    $this->advisor = advisorFor($this->branch, $this->service);

    // A channel that records what it was asked to send (and can be told to fail once).
    $this->channel = new class implements ReminderChannel
    {
        /** @var list<string> */
        public array $sent = [];

        public bool $failNext = false;

        public function send(Appointment $appointment): void
        {
            if ($this->failNext) {
                $this->failNext = false;

                throw new RuntimeException('provider down');
            }

            $this->sent[] = $appointment->reference;
        }
    };
    app()->instance(ReminderChannel::class, $this->channel);
});

it('sends a reminder exactly once, however often the scheduler and queue run', function (): void {
    $appointment = bookedFor($this->advisor, $this->service, '2026-12-02 09:00');   // 23 hours away

    $this->artisan('branchbook:send-reminders')->expectsOutputToContain('Queued 1 reminder')->assertSuccessful();
    $this->artisan('branchbook:send-reminders')->expectsOutputToContain('Queued 0 reminder');   // already claimed
    SendAppointmentReminder::dispatchSync($appointment->id);                                      // duplicate job
    SendAppointmentReminder::dispatchSync($appointment->id);

    expect($this->channel->sent)->toBe([$appointment->reference])
        ->and($appointment->fresh()->reminder_sent_at)->not->toBeNull();
});

it('only reminds confirmed appointments starting within 24 hours', function (): void {
    $soon = bookedFor($this->advisor, $this->service, '2026-12-02 09:00');
    bookedFor($this->advisor, $this->service, '2026-12-03 09:00');                                      // ~47 h away
    bookedFor($this->advisor, $this->service, '2026-12-02 10:00', AppointmentStatus::Cancelled);        // cancelled
    bookedFor($this->advisor, $this->service, '2026-12-02 11:00', AppointmentStatus::Held, nowAt()->addMinutes(5)); // not verified

    $this->artisan('branchbook:send-reminders')->expectsOutputToContain('Queued 1 reminder');

    expect($this->channel->sent)->toBe([$soon->reference]);
});

it('releases the claim when sending fails so the retry can send', function (): void {
    $appointment = bookedFor($this->advisor, $this->service, '2026-12-02 09:00');
    $this->channel->failNext = true;

    expect(fn () => SendAppointmentReminder::dispatchSync($appointment->id))->toThrow(RuntimeException::class, 'provider down');
    expect($appointment->fresh()->reminder_sent_at)->toBeNull()->and($this->channel->sent)->toBe([]);

    SendAppointmentReminder::dispatchSync($appointment->id);   // the queue's retry

    expect($this->channel->sent)->toBe([$appointment->reference]);
});

it('does not remind an appointment that was cancelled after the job was queued', function (): void {
    $appointment = bookedFor($this->advisor, $this->service, '2026-12-02 09:00');
    $appointment->transitionTo(AppointmentStatus::Cancelled);

    SendAppointmentReminder::dispatchSync($appointment->id);

    expect($this->channel->sent)->toBe([]);
});

it('uses the log channel by default and logs no personal data', function (): void {
    app()->forgetInstance(ReminderChannel::class);
    app()->bind(ReminderChannel::class, LogChannel::class);
    Log::spy();

    $appointment = bookedFor($this->advisor, $this->service, '2026-12-02 09:00');
    SendAppointmentReminder::dispatchSync($appointment->id);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($appointment): bool {
        $flat = json_encode($context);

        return $message === 'Appointment reminder sent'
            && $context['reference'] === $appointment->reference
            && ! str_contains((string) $flat, $appointment->customer->email)
            && ! str_contains((string) $flat, 'phone');
    })->once();
});

it('marks no-shows 30 minutes after the start, only for appointments nobody checked in', function (): void {
    $late = bookedFor($this->advisor, $this->service, '2026-12-01 09:00');         // 05:00 UTC; now 06:00 -> 60 min late
    $justOver = bookedFor($this->advisor, $this->service, '2026-12-01 09:30');     // 05:30 UTC -> exactly 30 min
    $notYet = bookedFor($this->advisor, $this->service, '2026-12-01 09:45');       // 05:45 UTC -> 15 min
    $arrived = bookedFor($this->advisor, $this->service, '2026-12-01 08:00', AppointmentStatus::CheckedIn);
    $future = bookedFor($this->advisor, $this->service, '2026-12-02 09:00');

    $count = app(MarkNoShows::class)->handle();

    expect($count)->toBe(2)
        ->and($late->fresh()->status)->toBe(AppointmentStatus::NoShow)
        ->and($justOver->fresh()->status)->toBe(AppointmentStatus::NoShow)
        ->and($notYet->fresh()->status)->toBe(AppointmentStatus::Confirmed)
        ->and($arrived->fresh()->status)->toBe(AppointmentStatus::CheckedIn)
        ->and($future->fresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('runs the no-show command', function (): void {
    bookedFor($this->advisor, $this->service, '2026-12-01 09:00');

    $this->artisan('branchbook:mark-no-shows')->expectsOutputToContain('Marked 1 no-show')->assertSuccessful();
});

it('registers all three jobs with the scheduler', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('branchbook:expire-holds')
        ->expectsOutputToContain('branchbook:send-reminders')
        ->expectsOutputToContain('branchbook:mark-no-shows')
        ->assertSuccessful();
});
