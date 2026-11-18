<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Advisor;
use Illuminate\Foundation\Http\FormRequest;

class AdvisorShiftRequest extends FormRequest
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
            'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ];
    }
}
