<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Http\Requests\Admin\ServiceRequest;
use App\Models\Service;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\CreateOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\UpdateOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

/** Services are bank-wide: HQ edits them, branch managers can only look. Services are deactivated, never deleted. */
class ServiceCrudController extends CrudController
{
    use CreateOperation, ListOperation, ScopesToBranch, ShowOperation, UpdateOperation;

    public function setup(): void
    {
        CRUD::setModel(Service::class);
        CRUD::setRoute(backpack_url('service'));
        CRUD::setEntityNameStrings('service', 'services');

        if (! backpack_user()->isHq()) {
            CRUD::denyAccess(['create', 'update']);
        }
    }

    protected function setupListOperation(): void
    {
        CRUD::column('code');
        CRUD::column('name');
        CRUD::column('category');
        CRUD::column('duration_minutes')->label('Minutes');
        CRUD::column('buffer_minutes')->label('Buffer');
        CRUD::column('queue_prefix')->label('Ticket letter');
        CRUD::column('is_active')->type('boolean')->label('Active');

        $this->addFilterOnce(['name' => 'category', 'type' => 'dropdown', 'label' => 'Category'],
            fn (): array => Service::query()->orderBy('category')->pluck('category', 'category')->all(),
            fn (string $value) => CRUD::addClause('where', 'category', $value));
    }

    protected function setupShowOperation(): void
    {
        $this->setupListOperation();
        CRUD::column('description');
        CRUD::column('required_documents_text')->label('Documents to bring')->type('textarea');
        CRUD::column('min_notice_hours');
        CRUD::column('max_days_ahead');
    }

    protected function setupCreateOperation(): void
    {
        CRUD::setValidation(ServiceRequest::class);

        CRUD::field('code')->hint('Unique, capitals');
        CRUD::field('name');
        CRUD::field('category');
        CRUD::field('description')->type('textarea');
        CRUD::field('duration_minutes')->type('number')->label('Meeting length (minutes)');
        CRUD::field('buffer_minutes')->type('number')->label('Wrap-up buffer after the meeting (minutes)')->default(0);
        CRUD::field('queue_prefix')->label('Queue ticket letter')->hint('One capital letter, e.g. A gives A-042');
        // A checklist with one document per line (stored as a JSON list; see Service::requiredDocumentsText).
        CRUD::field('required_documents_text')->type('textarea')->label('Documents to bring (one per line)');
        CRUD::field('min_notice_hours')->type('number')->label('Minimum notice (hours)')->default(2);
        CRUD::field('max_days_ahead')->type('number')->label('Bookable up to (days ahead)')->default(30);
        CRUD::field('is_active')->type('checkbox')->label('Active')->default(true);
    }

    protected function setupUpdateOperation(): void
    {
        $this->setupCreateOperation();
    }
}
