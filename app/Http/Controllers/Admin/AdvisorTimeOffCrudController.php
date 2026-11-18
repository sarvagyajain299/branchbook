<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Http\Requests\Admin\AdvisorTimeOffRequest;
use App\Models\Advisor;
use App\Models\AdvisorTimeOff;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

/** Leave and training periods: while one is active the advisor gets no new bookings. */
class AdvisorTimeOffCrudController extends CrudController
{
    use CreateOperation, DeleteOperation, ListOperation, ScopesToBranch, UpdateOperation;

    public function setup(): void
    {
        CRUD::setModel(AdvisorTimeOff::class);
        CRUD::setRoute(backpack_url('advisor-time-off'));
        CRUD::setEntityNameStrings('time off', 'time off');

        $this->scopeToBranchViaAdvisor();
        CRUD::with('advisor.user');
        CRUD::orderBy('starts_at', 'desc');
    }

    protected function setupListOperation(): void
    {
        $tz = (string) config('app.display_timezone');

        CRUD::column('advisor')->type('closure')->function(fn (AdvisorTimeOff $t): string => $t->advisor->user->name);
        CRUD::column('starts_at')->type('closure')->label('From (Dubai)')->function(fn (AdvisorTimeOff $t): string => $t->starts_at->setTimezone($tz)->format('Y-m-d H:i'));
        CRUD::column('ends_at')->type('closure')->label('To (Dubai)')->function(fn (AdvisorTimeOff $t): string => $t->ends_at->setTimezone($tz)->format('Y-m-d H:i'));
        CRUD::column('reason');
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(AdvisorTimeOffRequest::class);

        CRUD::field('advisor_id')->type('select')->label('Advisor')->entity('advisor')->model(Advisor::class)->attribute('label')
            ->options(fn ($query) => $query->with('user')->when(! backpack_user()->isHq(), fn ($q) => $q->where('branch_id', backpack_user()->branch_id))->get());
        CRUD::field('starts_at')->type('datetime');
        CRUD::field('ends_at')->type('datetime');
        CRUD::field('reason');
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }
}
