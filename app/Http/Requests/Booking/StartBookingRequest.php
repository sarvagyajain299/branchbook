<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class StartBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public form; abuse is limited by throttling and the OTP step
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            // Accept "050 123 4567", "+971-50-123-4567", "0501234567" ...
            'phone' => preg_replace('/[\s\-().]/', '', (string) $this->input('phone')),
        ]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc', 'max:190'],
            // UAE mobile: optional +971 / 00971 / 0, then 5X and 7 digits (X = 0,2,4,5,6,8).
            'phone' => ['required', 'regex:/^(?:\+971|00971|0)?5[024568]\d{7}$/'],
            'consent' => ['accepted'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a UAE mobile number, for example 050 123 4567.',
            'consent.accepted' => 'Please confirm that you agree to us using your details for this appointment.',
        ];
    }

    /** "0501234567" -> "+971501234567" */
    public function phoneE164(): string
    {
        $national = preg_replace('/^(?:\+971|00971|0)/', '', (string) $this->validated('phone')) ?? '';

        return '+971'.$national;
    }
}
