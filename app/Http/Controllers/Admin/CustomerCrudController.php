<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Models\Appointment;
use App\Models\Customer;
use App\Support\Audit;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Prologue\Alerts\Facades\Alert;

/**
 * Customers with MASKED contact details. Seeing the real email and phone is a separate, audited
 * action ("Reveal"), allowed only for roles the policy permits. A manager only reaches customers
 * who have an appointment at their branch.
 */
class CustomerCrudController extends CrudController
{
    use ListOperation, ScopesToBranch, ShowOperation;

    public function setup(): void
    {
        CRUD::setModel(Customer::class);
        CRUD::setRoute(backpack_url('customer'));
        CRUD::setEntityNameStrings('customer', 'customers');

        $user = backpack_user();
        if (! $user->isHq()) {
            CRUD::addClause('whereHas', 'appointments', fn ($q) => $q->where('branch_id', $user->branch_id));
        }
        CRUD::orderBy('id', 'desc');
        CRUD::allowAccess('revealContact');
    }

    protected function setupListOperation(): void
    {
        CRUD::column('name')->type('closure')->label('Name')->function(fn (Customer $c): string => $c->fullName());
        CRUD::column('email')->type('closure')->label('Email (masked)')->function(fn (Customer $c): string => $c->maskedEmail());
        CRUD::column('phone')->type('closure')->label('Phone (masked)')->function(fn (Customer $c): string => $c->maskedPhone());
        CRUD::column('email_verified_at')->type('closure')->label('Verified')->function(fn (Customer $c): string => $c->email_verified_at === null ? 'No' : 'Yes');

        // Search by name only: the phone is encrypted (cannot be searched) and the email is never shown in full.
        $this->addFilterOnce(['name' => 'verified', 'type' => 'simple', 'label' => 'Verified only'], false,
            fn () => CRUD::addClause('whereNotNull', 'email_verified_at'));
    }

    protected function setupShowOperation(): void
    {
        $this->setupListOperation();
        $tz = (string) config('app.display_timezone');

        CRUD::column('marketing_opt_in')->type('boolean')->label('Marketing opt-in');
        CRUD::column('anonymised_at')->type('closure')->label('Anonymised')->function(fn (Customer $c): string => $c->anonymised_at === null ? 'No' : 'Yes');
        CRUD::column('bookings')->label('Booking history')->type('closure')->escaped(false)->function(function (Customer $c) use ($tz): string {
            $user = backpack_user();
            $rows = $c->appointments()->with(['branch', 'service'])
                ->when(! $user->isHq(), fn ($q) => $q->where('branch_id', $user->branch_id))
                ->orderByDesc('starts_at')->get();

            return $rows->isEmpty() ? '<span class="text-muted">None at your branches.</span>'
                : $rows->map(fn (Appointment $a): string => e($a->reference.'  '.$a->starts_at->setTimezone($tz)->format('Y-m-d H:i').'  '.$a->service->name.'  '.$a->branch->code.'  '.$a->status->label()))->implode('<br>');
        });

        CRUD::addButtonFromView('line', 'reveal_contact', 'reveal_contact', 'end');
    }

    /** Shows the full contact details once (in a flash message) and writes the reveal to the audit log. */
    public function reveal(int|string $id): RedirectResponse
    {
        /** @var Customer $customer */
        $customer = $this->crud->getEntry($id);
        Gate::authorize('revealContactDetails', $customer);

        // The audit entry holds the customer id and the actor, never the details that were revealed.
        Audit::record('customer.contact_revealed', $customer, [], backpack_user());
        Alert::info('Email: '.e($customer->email).' · Phone: '.e($customer->phone).'. This view was recorded in the audit log.')->flash();

        return redirect()->back();
    }
}
