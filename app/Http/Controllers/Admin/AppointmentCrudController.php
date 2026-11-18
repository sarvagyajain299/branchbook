<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\StaffAppointmentActions;
use App\Enums\AppointmentStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Models\Advisor;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Service;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Prologue\Alerts\Facades\Alert;

/**
 * Appointments are created online (or by the booking actions) and only ever change status through the
 * state machine, so the panel offers list, show, cancel and no-show: no free-form create or edit.
 */
class AppointmentCrudController extends CrudController
{
    use ListOperation, ScopesToBranch, ShowOperation;

    public function setup(): void
    {
        CRUD::setModel(Appointment::class);
        CRUD::setRoute(backpack_url('appointment'));
        CRUD::setEntityNameStrings('appointment', 'appointments');

        // visibleTo(): HQ everything, managers their branch. Same scope the rest of the app uses.
        CRUD::addClause('visibleTo', backpack_user());
        CRUD::with(['branch', 'service', 'advisor.user']);
        CRUD::orderBy('starts_at', 'desc');
        CRUD::allowAccess(['cancelAppointment', 'noShowAppointment']);
    }

    protected function setupListOperation(): void
    {
        $tz = (string) config('app.display_timezone');

        CRUD::column('reference');
        CRUD::column('starts_at')->type('closure')->label('When (Dubai)')->function(fn (Appointment $a): string => $a->starts_at->setTimezone($tz)->format('Y-m-d H:i'));
        CRUD::column('branch')->type('relationship')->attribute('code');
        CRUD::column('service')->type('relationship')->attribute('name');
        CRUD::column('advisor')->type('closure')->function(fn (Appointment $a): string => $a->advisor->user->name);
        CRUD::column('status')->type('closure')->function(fn (Appointment $a): string => $a->status->label());

        $this->addFilterOnce(['name' => 'branch', 'type' => 'dropdown', 'label' => 'Branch'],
            fn (): array => Branch::query()->visibleTo(backpack_user())->orderBy('name')->pluck('name', 'id')->all(),
            fn (string $value) => CRUD::addClause('where', 'branch_id', (int) $value));
        $this->addFilterOnce(['name' => 'service', 'type' => 'dropdown', 'label' => 'Service'],
            fn (): array => Service::query()->orderBy('name')->pluck('name', 'id')->all(),
            fn (string $value) => CRUD::addClause('where', 'service_id', (int) $value));
        $this->addFilterOnce(['name' => 'status', 'type' => 'dropdown', 'label' => 'Status'],
            fn (): array => collect(AppointmentStatus::cases())->mapWithKeys(fn (AppointmentStatus $s): array => [$s->value => $s->label()])->all(),
            fn (string $value) => CRUD::addClause('where', 'status', $value));
        $this->addFilterOnce(['name' => 'advisor', 'type' => 'dropdown', 'label' => 'Advisor'],
            fn (): array => Advisor::query()->with('user')->when(! backpack_user()->isHq(), fn ($q) => $q->where('branch_id', backpack_user()->branch_id))->get()->mapWithKeys(fn (Advisor $a): array => [$a->id => $a->user->name])->all(),
            fn (string $value) => CRUD::addClause('where', 'advisor_id', (int) $value));
        $this->addFilterOnce(['name' => 'day', 'type' => 'date_range', 'label' => 'Date (Dubai)'], false, function (string $value) use ($tz): void {
            $range = json_decode($value);

            if (! empty($range->from) && ! empty($range->to)) {
                CRUD::addClause('where', 'starts_at', '>=', CarbonImmutable::parse($range->from, $tz)->startOfDay()->utc());
                CRUD::addClause('where', 'starts_at', '<', CarbonImmutable::parse($range->to, $tz)->startOfDay()->addDay()->utc());
            }
        });

        CRUD::addButtonFromView('line', 'cancel_appointment', 'cancel_appointment', 'end');
        CRUD::addButtonFromView('line', 'no_show_appointment', 'no_show_appointment', 'end');
    }

    protected function setupShowOperation(): void
    {
        $this->setupListOperation();
        $tz = (string) config('app.display_timezone');

        CRUD::column('ends_at')->type('closure')->label('Ends (Dubai)')->function(fn (Appointment $a): string => $a->ends_at->setTimezone($tz)->format('Y-m-d H:i'));
        CRUD::column('channel')->type('closure')->function(fn (Appointment $a): string => $a->channel->label());
        CRUD::column('cancelled_reason');
        CRUD::column('history')->label('Status history (audit log)')->type('closure')->escaped(false)->function(function (Appointment $a) use ($tz): string {
            $rows = AuditLog::query()->where('subject_type', 'Appointment')->where('subject_id', $a->id)->orderBy('id')->get();

            return $rows->isEmpty() ? '<span class="text-muted">No recorded changes.</span>'
                : $rows->map(fn (AuditLog $l): string => e($l->created_at->setTimezone($tz)->format('Y-m-d H:i').'  '.$l->action.'  '.json_encode($l->changes)))->implode('<br>');
        });
    }

    public function cancel(int|string $id, StaffAppointmentActions $actions): RedirectResponse
    {
        /** @var Appointment $appointment already restricted to the manager's branch: another branch gives 404 */
        $appointment = $this->crud->getEntry($id);
        Gate::authorize('cancel', $appointment);

        try {
            $actions->cancel($appointment, backpack_user());
            Alert::success("Appointment {$appointment->reference} cancelled.")->flash();
        } catch (InvalidStatusTransition $e) {
            Alert::error($e->getMessage())->flash();
        }

        return redirect()->back();
    }

    public function noShow(int|string $id, StaffAppointmentActions $actions): RedirectResponse
    {
        /** @var Appointment $appointment */
        $appointment = $this->crud->getEntry($id);
        Gate::authorize('markNoShow', $appointment);

        try {
            $actions->markNoShow($appointment, backpack_user());
            Alert::success("Appointment {$appointment->reference} marked as no-show.")->flash();
        } catch (InvalidStatusTransition $e) {
            Alert::error($e->getMessage())->flash();
        }

        return redirect()->back();
    }
}
