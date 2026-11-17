<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking as the customer sees it. Contains no personal data of the customer (they already
 * know it) and nothing about which advisor was assigned.
 *
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'status' => $this->status->value,
            'starts_at' => $this->starts_at->toIso8601ZuluString(),
            'ends_at' => $this->ends_at->toIso8601ZuluString(),
            'local_start' => $this->localStart()->format('Y-m-d H:i'),
            'timezone' => config('app.display_timezone'),
            'hold_expires_at' => $this->hold_expires_at?->toIso8601ZuluString(),
            'service' => ['code' => $this->service->code, 'name' => $this->service->name],
            'branch' => ['code' => $this->branch->code, 'name' => $this->branch->name, 'address' => $this->branch->address],
            'required_documents' => $this->service->required_documents,
        ];
    }
}
