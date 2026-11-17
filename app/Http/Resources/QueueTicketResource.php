<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\QueueTicket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin QueueTicket */
class QueueTicketResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->display_code,
            'status' => $this->status->value,
            'service' => ['code' => $this->service->code, 'name' => $this->service->name],
            'is_walk_in' => $this->appointment_id === null,
            'desk' => $this->desk_label,
            'issued_at' => $this->issued_at->toIso8601ZuluString(),
            'called_at' => $this->called_at?->toIso8601ZuluString(),
            'served_at' => $this->served_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
