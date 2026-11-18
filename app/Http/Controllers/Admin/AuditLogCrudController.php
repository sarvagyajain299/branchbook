<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ScopesToBranch;
use App\Models\AuditLog;
use Backpack\CRUD\app\Http\Controllers\CrudController;
use Backpack\CRUD\app\Http\Controllers\Operations\ListOperation;
use Backpack\CRUD\app\Http\Controllers\Operations\ShowOperation;
use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;
use Carbon\CarbonImmutable;

/** Read-only viewer for the append-only audit log. HQ administrators only. */
class AuditLogCrudController extends CrudController
{
    use ListOperation, ScopesToBranch, ShowOperation;

    public function setup(): void
    {
        $this->hqOnly();

        CRUD::setModel(AuditLog::class);
        CRUD::setRoute(backpack_url('audit-log'));
        CRUD::setEntityNameStrings('audit entry', 'audit log');
        CRUD::orderBy('id', 'desc');
    }

    protected function setupListOperation(): void
    {
        $tz = (string) config('app.display_timezone');

        CRUD::column('created_at')->type('closure')->label('When (Dubai)')->function(fn (AuditLog $l): string => $l->created_at->setTimezone($tz)->format('Y-m-d H:i:s'));
        CRUD::column('actor_id')->type('closure')->label('Actor')->function(fn (AuditLog $l): string => $l->actor_id === null ? 'System / customer' : "User #{$l->actor_id}");
        CRUD::column('action');
        CRUD::column('subject_type')->label('Subject');
        CRUD::column('subject_id')->label('Subject id');

        $this->addFilterOnce(['name' => 'action', 'type' => 'dropdown', 'label' => 'Action'],
            fn (): array => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all(),
            fn (string $value) => CRUD::addClause('where', 'action', $value));
        $this->addFilterOnce(['name' => 'subject', 'type' => 'dropdown', 'label' => 'Subject type'],
            fn (): array => AuditLog::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type', 'subject_type')->all(),
            fn (string $value) => CRUD::addClause('where', 'subject_type', $value));
        $this->addFilterOnce(['name' => 'actor', 'type' => 'text', 'label' => 'Actor id'], false,
            fn (string $value) => CRUD::addClause('where', 'actor_id', (int) $value));
        $this->addFilterOnce(['name' => 'day', 'type' => 'date_range', 'label' => 'Date (Dubai)'], false, function (string $value) use ($tz): void {
            $range = json_decode($value);

            if (! empty($range->from) && ! empty($range->to)) {
                CRUD::addClause('where', 'created_at', '>=', CarbonImmutable::parse($range->from, $tz)->startOfDay()->utc());
                CRUD::addClause('where', 'created_at', '<', CarbonImmutable::parse($range->to, $tz)->startOfDay()->addDay()->utc());
            }
        });
    }

    protected function setupShowOperation(): void
    {
        $this->setupListOperation();
        CRUD::column('changes')->type('closure')->escaped(true)->function(fn (AuditLog $l): string => (string) json_encode($l->changes, JSON_PRETTY_PRINT));
        CRUD::column('ip_hash')->label('IP (keyed hash)');
    }
}
