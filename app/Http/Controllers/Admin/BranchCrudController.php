<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Http\Requests\Admin\BranchRequest;
use App\Models\Branch;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\DeleteOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

/** HQ manages every branch; a branch manager can only view and edit their own. */
class BranchCrudController extends CrudController
{
    use CreateOperation, DeleteOperation, ListOperation, ScopesToBranch, ShowOperation, UpdateOperation;

    public function setup(): void
    {
        CRUD::setModel(Branch::class);
        CRUD::setRoute(backpack_url('branch'));
        CRUD::setEntityNameStrings('branch', 'branches');

        CRUD::addClause('visibleTo', backpack_user());

        if (! backpack_user()->isHq()) {
            CRUD::denyAccess(['create', 'delete']);
        }
    }

    protected function setupListOperation(): void
    {
        CRUD::column('code');
        CRUD::column('name');
        CRUD::column('city');
        CRUD::column('phone');
        CRUD::column('is_active')->type('boolean')->label('Active');

        $this->addFilterOnce(['name' => 'city', 'type' => 'dropdown', 'label' => 'City'],
            fn (): array => Branch::query()->visibleTo(backpack_user())->orderBy('city')->pluck('city', 'city')->all(),
            fn (string $value) => CRUD::addClause('where', 'city', $value));
        $this->addFilterOnce(['name' => 'active_only', 'type' => 'simple', 'label' => 'Active only'], false,
            fn () => CRUD::addClause('where', 'is_active', true));
    }

    protected function setupShowOperation(): void
    {
        $this->setupListOperation();
        CRUD::column('address');
        CRUD::column('latitude');
        CRUD::column('longitude');
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(BranchRequest::class);

        CRUD::field('code')->hint('Unique, capitals, e.g. DXB-MAR');
        CRUD::field('name');
        CRUD::field('city');
        CRUD::field('address');
        CRUD::field('latitude')->type('number')->attributes(['step' => 'any']);
        CRUD::field('longitude')->type('number')->attributes(['step' => 'any']);
        CRUD::field('phone');
        CRUD::field('is_active')->type('checkbox')->label('Active')->default(true);
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }
}
