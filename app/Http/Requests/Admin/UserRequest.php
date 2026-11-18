<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return backpack_auth()->check() && backpack_user()->isHq();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $id = $this->route('id') ?? $this->input('id');
        $isHqRole = $this->input('role') === Role::HqAdmin->value;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'role' => ['required', Rule::enum(Role::class)],
            // HQ staff belong to no branch; everyone else must belong to one.
            'branch_id' => $isHqRole ? ['nullable', 'prohibited'] : ['required', 'integer', 'exists:branches,id'],
            'password' => [$id === null ? 'required' : 'nullable', 'string', Password::min(12)->mixedCase()->numbers()],
        ];
    }
}
