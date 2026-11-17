<?php

declare(strict_types=1);

namespace App\Http\Requests\Branch;

use Illuminate\Foundation\Http\FormRequest;

class WalkInRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // role and branch are checked with the QueueTicket policy in the controller
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'name' => ['nullable', 'string', 'max:60'],
        ];
    }
}
