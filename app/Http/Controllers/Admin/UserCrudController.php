<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Http\Requests\Admin\UserRequest;
use App\Models\Branch;
use App\Models\User;
use App\Support\Audit;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\Facades\Alert;

/**
 * Staff accounts and roles: HQ administrators only. `role` and `branch_id` are deliberately not
 * mass-assignable on the model (a posted form must never be able to promote someone), so this
 * controller sets them explicitly and records every change in the audit log.
 */
class UserCrudController extends CrudController
{
    use CreateOperation { store as traitStore; }
    use ListOperation, ScopesToBranch, ShowOperation;
    use UpdateOperation { update as traitUpdate; }

    public function setup(): void
    {
        $this->hqOnly();

        CRUD::setModel(User::class);
        CRUD::setRoute(backpack_url('user'));
        CRUD::setEntityNameStrings('staff user', 'staff users');
        CRUD::with('branch');
    }

    protected function setupListOperation(): void
    {
        CRUD::column('name');
        CRUD::column('email');
        CRUD::column('role')->type('closure')->function(fn (User $u): string => $u->role->label());
        CRUD::column('branch')->type('closure')->function(fn (User $u): string => $u->branch === null ? 'HQ' : $u->branch->code);

        $this->addFilterOnce(['name' => 'role', 'type' => 'dropdown', 'label' => 'Role'],
            fn (): array => collect(Role::cases())->mapWithKeys(fn (Role $r): array => [$r->value => $r->label()])->all(),
            fn (string $value) => CRUD::addClause('where', 'role', $value));
    }

    protected function setupShowOperation(): void
    {
        $this->setupListOperation();
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(UserRequest::class);

        CRUD::field('name');
        CRUD::field('email')->type('email');
        CRUD::field('role')->type('select_from_array')
            ->options(collect(Role::cases())->mapWithKeys(fn (Role $r): array => [$r->value => $r->label()])->all());
        CRUD::field('branch_id')->type('select')->label('Branch (empty for HQ)')->entity('branch')->attribute('name')->model(Branch::class)->allows_null(true);
        CRUD::field('password')->type('password')->hint('At least 12 characters with upper and lower case and a number. Leave empty on edit to keep the current one.');
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }

    public function store(): RedirectResponse
    {
        $this->crud->hasAccessOrFail('create');
        $request = $this->crud->validateRequest();

        $user = new User;
        $this->fill($user, $request->only(['name', 'email', 'role', 'branch_id', 'password']));
        $user->email_verified_at = now();   // accounts are created by HQ, so the address is trusted
        $user->save();

        Audit::record('user.created', $user, ['role' => $user->role->value, 'branch_id' => $user->branch_id], backpack_user());
        Alert::success('Staff user created.')->flash();

        return redirect(backpack_url('user'));
    }

    public function update(): RedirectResponse
    {
        $this->crud->hasAccessOrFail('update');
        $request = $this->crud->validateRequest();

        $user = User::query()->findOrFail($request->input('id'));
        $before = ['role' => $user->role->value, 'branch_id' => $user->branch_id];
        $this->fill($user, $request->only(['name', 'email', 'role', 'branch_id', 'password']));
        $user->save();

        Audit::record('user.updated', $user, ['role' => [$before['role'], $user->role->value], 'branch_id' => [$before['branch_id'], $user->branch_id]], backpack_user());
        Alert::success('Staff user updated.')->flash();

        return redirect(backpack_url('user'));
    }

    /** @param  array<string, mixed>  $data */
    private function fill(User $user, array $data): void
    {
        $user->name = (string) $data['name'];
        $user->email = mb_strtolower((string) $data['email']);
        $user->role = Role::from((string) $data['role']);
        $user->branch_id = $user->role === Role::HqAdmin || empty($data['branch_id']) ? null : (int) $data['branch_id'];

        if (! empty($data['password'])) {
            $user->password = (string) $data['password'];   // hashed by the model cast
        }
    }
}
