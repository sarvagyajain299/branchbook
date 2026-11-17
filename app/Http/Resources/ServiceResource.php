<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Service */
class ServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'category' => $this->category,
            'description' => $this->description,
            'duration_minutes' => $this->duration_minutes,
            'required_documents' => $this->required_documents,
            'min_notice_hours' => $this->min_notice_hours,
            'max_days_ahead' => $this->max_days_ahead,
        ];
    }
}
