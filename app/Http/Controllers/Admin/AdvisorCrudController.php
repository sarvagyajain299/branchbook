<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Http\Requests\Admin\AdvisorRequest;
use App\Models\Advisor;
use App\Models\Branch;
use App\Models\Service;
use App\Models\User;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

/** Advisors with their skills (services) and desk. Deactivate instead of deleting: appointments reference them. */
class AdvisorCrudController extends CrudController
{
    use CreateOperation, ListOperation, ScopesToBranch, ShowOperation, UpdateOperation;

    public function setup(): void
    {
        CRUD::setModel(Advisor::class);
        CRUD::setRoute(backpack_url('advisor'));
        CRUD::setEntityNameStrings('advisor', 'advisors');

        $this->scopeToBranch();
        CRUD::with(['user', 'branch', 'services']);
    }

    protected function setupListOperation(): void
    {
        CRUD::column('user')->type('closure')->label('Advisor')->function(fn (Advisor $a): string => $a->user->name);
        CRUD::column('branch')->type('relationship')->attribute('code');
        CRUD::column('desk_label')->label('Desk');
        CRUD::column('services')->type('closure')->label('Skills')->function(fn (Advisor $a): string => $a->services->pluck('code')->sort()->implode(', '));
        CRUD::column('is_active')->type('boolean')->label('Active');

        $this->addFilterOnce(['name' => 'branch', 'type' => 'dropdown', 'label' => 'Branch'],
            fn (): array => Branch::query()->visibleTo(backpack_user())->orderBy('name')->pluck('name', 'id')->all(),
            fn (string $value) => CRUD::addClause('where', 'branch_id', (int) $value));
        $this->addFilterOnce(['name' => 'service', 'type' => 'dropdown', 'label' => 'Qualified for'],
            fn (): array => Service::query()->orderBy('name')->pluck('name', 'id')->all(),
            fn (string $value) => CRUD::addClause('whereHas', 'services', fn ($q) => $q->whereKey((int) $value)));
        $this->addFilterOnce(['name' => 'active_only', 'type' => 'simple', 'label' => 'Active only'], false,
            fn () => CRUD::addClause('where', 'is_active', true));
    }

    protected function setupShowOperation(): void
    {
        $this->setupListOperation();
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(AdvisorRequest::class);

        CRUD::field('branch_id')->type('select')->label('Branch')->entity('branch')->attribute('name')->model(Branch::class)
            ->options(fn ($query) => $query->visibleTo(backpack_user())->orderBy('name')->get());
        CRUD::field('user_id')->type('select')->label('Login (advisor account)')->entity('user')->attribute('name')->model(User::class)
            ->options(fn ($query) => $query->where('role', Role::Advisor->value)
                ->when(! backpack_user()->isHq(), fn ($q) => $q->where('branch_id', backpack_user()->branch_id))->orderBy('name')->get());
        CRUD::field('desk_label')->label('Desk label')->hint('Shown on the waiting-room screen, e.g. Desk 3');
        CRUD::field('services')->type('select_multiple')->label('Qualified for these services')->entity('services')->attribute('name')->model(Service::class)->pivot(true);
        CRUD::field('is_active')->type('checkbox')->label('Active')->default(true);
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }
}
