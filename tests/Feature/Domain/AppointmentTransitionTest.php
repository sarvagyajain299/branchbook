<?php

declare(strict_types=1);

use App\Enums\AppointmentStatus;
use App\Enums\TicketStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Appointment;
use App\Models\QueueTicket;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('moves an appointment through a valid transition and saves it', function (): void {
    $appointment = Appointment::factory()->status(AppointmentStatus::Confirmed)->create();

    $appointment->transitionTo(AppointmentStatus::CheckedIn);

    expect($appointment->fresh()->status)->toBe(AppointmentStatus::CheckedIn);
});

it('rejects an invalid appointment transition and leaves the row unchanged', function (AppointmentStatus $from, AppointmentStatus $to): void {
    $appointment = Appointment::factory()->status($from)->create();

    expect(fn () => $appointment->transitionTo($to))
        ->toThrow(InvalidStatusTransition::class, "Cannot change status from '{$from->value}' to '{$to->value}'.");

    expect($appointment->fresh()->status)->toBe($from);
})->with([
    'completed -> held' => [AppointmentStatus::Completed, AppointmentStatus::Held],
    'cancelled -> confirmed' => [AppointmentStatus::Cancelled, AppointmentStatus::Confirmed],
    'confirmed -> completed (skips the visit)' => [AppointmentStatus::Confirmed, AppointmentStatus::Completed],
    'expired -> confirmed' => [AppointmentStatus::Expired, AppointmentStatus::Confirmed],
    'no_show -> checked_in' => [AppointmentStatus::NoShow, AppointmentStatus::CheckedIn],
]);

it('does not let status be set by mass assignment', function (): void {
    expect(fn () => new Appointment(['status' => 'completed']))->toThrow(MassAssignmentException::class);
});

it('applies the same transition rules to queue tickets', function (): void {
    $ticket = QueueTicket::factory()->status(TicketStatus::Waiting)->create();

    $ticket->transitionTo(TicketStatus::Called);
    expect($ticket->fresh()->status)->toBe(TicketStatus::Called);

    expect(fn () => $ticket->transitionTo(TicketStatus::Completed))->toThrow(InvalidStatusTransition::class);
});
