<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Http\Requests\Admin\Concerns\ChecksBranchScope;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvisorRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id', Rule::unique('advisors', 'user_id')->ignore($this->editedId()),
                // The linked login must really be an advisor account at the same branch.
                function (string $attribute, mixed $value, callable $fail): void {
                    $user = User::query()->find($value);

                    if ($user === null || $user->role !== Role::Advisor || (int) $user->branch_id !== (int) $this->input('branch_id')) {
                        $fail('Choose a user with the advisor role at this branch.');
                    }
                }],
            'branch_id' => $this->branchRule(),
            'desk_label' => ['required', 'string', 'max:30'],
            'is_active' => ['nullable', 'boolean'],
            'services' => ['nullable', 'array'],
            'services.*' => ['integer', 'exists:services,id'],
        ];
    }
}
