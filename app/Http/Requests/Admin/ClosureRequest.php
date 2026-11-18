<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ChecksBranchScope;
use Illuminate\Foundation\Http\FormRequest;

class ClosureRequest extends FormRequest
{
    use ChecksBranchScope;

    public function authorize(): bool
    {
        return backpack_auth()->check();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Empty = every branch (a public holiday); only HQ may do that.
            'branch_id' => $this->branchRule(nullable: true),
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
