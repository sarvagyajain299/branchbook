<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ChecksBranchScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchHourRequest extends FormRequest
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
            'branch_id' => $this->branchRule(),
            // One row per branch per weekday (also enforced by a unique index).
            'weekday' => ['required', 'integer', 'between:1,7', Rule::unique('branch_hours', 'weekday')->where('branch_id', $this->input('branch_id'))->ignore($this->editedId())],
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
            'break_start' => ['nullable', 'date_format:H:i', 'required_with:break_end', 'after:opens_at'],
            'break_end' => ['nullable', 'date_format:H:i', 'required_with:break_start', 'after:break_start', 'before:closes_at'],
        ];
    }
}
