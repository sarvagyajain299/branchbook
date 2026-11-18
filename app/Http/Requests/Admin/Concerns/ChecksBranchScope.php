<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Validation\Rule;

/** Branch managers may only create or change records for their own branch; HQ may use any. */
trait ChecksBranchScope
{
    /** @return list<mixed> rules for a required branch_id field */
    protected function branchRule(bool $nullable = false): array
    {
        $user = backpack_user();
        $rules = [$nullable ? 'nullable' : 'required', 'integer', 'exists:branches,id'];

        if (! $user->isHq()) {
            // A manager cannot even name another branch, and cannot leave it empty ("all branches").
            $rules = ['required', 'integer', Rule::in([$user->branch_id])];
        }

        return $rules;
    }

    /** The id of the record being edited, or null when creating. */
    protected function editedId(): mixed
    {
        return $this->route('id') ?? $this->input('id');
    }
}
