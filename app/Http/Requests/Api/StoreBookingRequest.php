<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'phone' => preg_replace('/[\s\-().]/', '', (string) $this->input('phone')),
        ]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'service' => ['required', 'string', 'exists:services,code'],
            'branch' => ['required', 'string', 'exists:branches,code'],
            'starts_at' => ['required', 'date'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'regex:/^(?:\+971|00971|0)?5[024568]\d{7}$/'],
            'consent' => ['accepted'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }

    public function phoneE164(): string
    {
        return '+971'.preg_replace('/^(?:\+971|00971|0)/', '', (string) $this->validated('phone'));
    }
}
