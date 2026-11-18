<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Http\Requests\Admin\BranchHourRequest;
use App\Models\Branch;
use App\Models\BranchHour;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

/** Opening hours per branch and weekday (a day with no row means closed). */
class BranchHourCrudController extends CrudController
{
    use CreateOperation, DeleteOperation, ListOperation, ScopesToBranch, UpdateOperation;

    public function setup(): void
    {
        CRUD::setModel(BranchHour::class);
        CRUD::setRoute(backpack_url('branch-hour'));
        CRUD::setEntityNameStrings('opening hours', 'opening hours');

        $this->scopeToBranch();
    }

    protected function setupListOperation(): void
    {
        CRUD::column('branch')->type('relationship')->attribute('code');
        CRUD::column('weekday')->type('closure')->function(fn (BranchHour $h): string => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$h->weekday]);
        CRUD::column('opens_at');
        CRUD::column('closes_at');
        CRUD::column('break_start');
        CRUD::column('break_end');
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(BranchHourRequest::class);

        CRUD::field('branch_id')->type('select')->label('Branch')->entity('branch')->attribute('name')->model(Branch::class)
            ->options(fn ($query) => $query->visibleTo(backpack_user())->orderBy('name')->get());
        CRUD::field('weekday')->type('select_from_array')->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday']);
        CRUD::field('opens_at')->type('time')->label('Opens (Dubai time)');
        CRUD::field('closes_at')->type('time')->label('Closes (Dubai time)');
        CRUD::field('break_start')->type('time')->hint('Optional lunch break');
        CRUD::field('break_end')->type('time');
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }
}
