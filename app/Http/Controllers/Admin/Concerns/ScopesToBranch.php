<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use Backpack\CRUD\app\Library\CrudPanel\CrudPanelFacade as CRUD;

/** List, show, edit and delete only ever reach rows of the manager's own branch. HQ sees all. */
trait ScopesToBranch
{
    /** For a table with its own branch_id column. */
    protected function scopeToBranch(string $column = 'branch_id'): void
    {
        $user = backpack_user();

        if (! $user->isHq()) {
            CRUD::addClause('where', $column, $user->branch_id);
        }
    }

    /** For rows that belong to an advisor (shifts, time off). */
    protected function scopeToBranchViaAdvisor(): void
    {
        $user = backpack_user();

        if (! $user->isHq()) {
            CRUD::addClause('whereHas', 'advisor', fn ($q) => $q->where('branch_id', $user->branch_id));
        }
    }

    /**
     * A list filter WITHOUT Backpack's filter API, which is part of its paid PRO package.
     *
     * The filter is remembered in a plain operation setting (rendered by the "bp_filters" button
     * view as an ordinary GET form) and, when its parameter is in the URL, its logic runs
     * immediately. The list page's AJAX search carries the same query string, so the table is
     * filtered. Calling this twice with the same name (list setup can run more than once) is safe.
     *
     * @param  array{name: string, type: string, label: string}  $options  type: dropdown, simple, text or date_range
     * @param  array<int|string, string>|callable|false  $values  dropdown choices (array or closure)
     */
    protected function addFilterOnce(array $options, mixed $values, ?callable $logic): void
    {
        $filters = CRUD::getOperationSetting('bpFilters') ?? [];

        foreach ($filters as $existing) {
            if ($existing['name'] === $options['name']) {
                return;
            }
        }

        if ($filters === []) {
            CRUD::addButtonFromView('top', 'bp_filters', 'bp_filters', 'beginning');
        }

        $filters[] = $options + ['values' => $values];
        CRUD::setOperationSetting('bpFilters', $filters);

        $name = $options['name'];
        $present = $options['type'] === 'simple' ? request()->has($name) : request()->filled($name);

        if ($present && $logic !== null) {
            $logic((string) request()->input($name));
        }
    }

    protected function hqOnly(): void
    {
        abort_unless(backpack_user()->isHq(), 403);
    }
}
