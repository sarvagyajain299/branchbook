<?php

declare(strict_types=1);

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['code' => preg_replace('/\s+/', '', (string) $this->input('code'))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'digits:6']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.digits' => 'The code is 6 digits.'];
    }
}
