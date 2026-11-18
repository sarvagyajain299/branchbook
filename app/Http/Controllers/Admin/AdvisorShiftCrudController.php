<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Http\Requests\Admin\AdvisorShiftRequest;
use App\Models\Advisor;
use App\Models\AdvisorShift;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

/** The recurring weekly pattern: which hours each advisor works on which weekday. */
class AdvisorShiftCrudController extends CrudController
{
    use CreateOperation, DeleteOperation, ListOperation, ScopesToBranch, UpdateOperation;

    public function setup(): void
    {
        CRUD::setModel(AdvisorShift::class);
        CRUD::setRoute(backpack_url('advisor-shift'));
        CRUD::setEntityNameStrings('weekly shift', 'weekly shifts');

        $this->scopeToBranchViaAdvisor();
        CRUD::with('advisor.user');
        CRUD::orderBy('advisor_id')->orderBy('weekday');
    }

    protected function setupListOperation(): void
    {
        CRUD::column('advisor')->type('closure')->function(fn (AdvisorShift $s): string => $s->advisor->user->name);
        CRUD::column('weekday')->type('closure')->function(fn (AdvisorShift $s): string => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$s->weekday]);
        CRUD::column('starts_at')->label('From');
        CRUD::column('ends_at')->label('To');
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(AdvisorShiftRequest::class);

        CRUD::field('advisor_id')->type('select')->label('Advisor')->entity('advisor')->attribute('label')->model(Advisor::class)
            ->options(fn ($query) => $query->with('user')->when(! backpack_user()->isHq(), fn ($q) => $q->where('branch_id', backpack_user()->branch_id))->get());
        CRUD::field('weekday')->type('select_from_array')->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday']);
        CRUD::field('starts_at')->type('time')->label('From (Dubai time)');
        CRUD::field('ends_at')->type('time')->label('To (Dubai time)');
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }
}
