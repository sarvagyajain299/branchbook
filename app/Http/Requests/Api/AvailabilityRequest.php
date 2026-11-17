<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class AvailabilityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', 'exists:services,code'],
            'date' => ['required', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,14'],
        ];
    }
}
