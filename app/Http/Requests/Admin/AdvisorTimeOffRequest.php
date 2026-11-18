<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Advisor;
use Illuminate\Foundation\Http\FormRequest;

class AdvisorTimeOffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return backpack_auth()->check();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'advisor_id' => ['required', 'integer', function (string $attribute, mixed $value, callable $fail): void {
                $user = backpack_user();
                $advisor = Advisor::query()->find($value);

                if ($advisor === null || (! $user->isHq() && $advisor->branch_id !== $user->branch_id)) {
                    $fail('Choose an advisor from your own branch.');
                }
            }],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
