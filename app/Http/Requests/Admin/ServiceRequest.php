<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return backpack_auth()->check() && backpack_user()->isHq();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9-]+$/', Rule::unique('services', 'code')->ignore($this->route('id') ?? $this->input('id'))],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'between:5,480'],
            'buffer_minutes' => ['required', 'integer', 'between:0,120'],
            // The letter printed on queue tickets, e.g. "A" for A-042.
            'queue_prefix' => ['required', 'string', 'size:1', 'regex:/^[A-Z]$/'],
            'required_documents_text' => ['nullable', 'string', 'max:2000'],
            'min_notice_hours' => ['required', 'integer', 'between:0,168'],
            'max_days_ahead' => ['required', 'integer', 'between:1,365'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
