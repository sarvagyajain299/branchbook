<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Http\Requests\Admin\ClosureRequest;
use App\Models\Branch;
use App\Models\Closure;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

/**
 * Public holidays (all branches) and one-off closures. Islamic holiday dates change every year,
 * so HQ edits them here instead of in code. Managers handle only their own branch's closures.
 */
class ClosureCrudController extends CrudController
{
    use CreateOperation, DeleteOperation, ListOperation, ScopesToBranch, UpdateOperation;

    public function setup(): void
    {
        CRUD::setModel(Closure::class);
        CRUD::setRoute(backpack_url('closure'));
        CRUD::setEntityNameStrings('closure', 'closures');

        $this->scopeToBranch();
        CRUD::orderBy('date', 'desc');
    }

    protected function setupListOperation(): void
    {
        CRUD::column('date')->type('date');
        CRUD::column('branch_id')->label('Branch')->type('closure')->function(fn (Closure $c): string => $c->branch_id === null ? 'All branches' : (string) $c->branch->code);
        CRUD::column('reason');

        CRUD::with('branch');
        $this->addFilterOnce(['name' => 'year', 'type' => 'dropdown', 'label' => 'Year'], fn (): array => array_combine(range(2026, 2028), range(2026, 2028)),
            fn (string $year) => CRUD::addClause('whereYear', 'date', (int) $year));
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(ClosureRequest::class);

        CRUD::field('branch_id')->type('select')->label('Branch')->entity('branch')->attribute('name')->model(Branch::class)
            ->allows_null(backpack_user()->isHq())->hint('Leave empty for a public holiday that closes every branch (HQ only)')
            ->options(fn ($query) => $query->visibleTo(backpack_user())->orderBy('name')->get());
        CRUD::field('date')->type('date');
        CRUD::field('reason')->hint('For Islamic holidays add "(verify Islamic dates)" until the date is confirmed');
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }
}
