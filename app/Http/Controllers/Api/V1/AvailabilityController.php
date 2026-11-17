<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Data\Slot;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AvailabilityRequest;
use App\Models\Branch;
use App\Models\Service;
use App\Services\Availability\AvailabilityService;
use Illuminate\Http\JsonResponse;

class AvailabilityController extends Controller
{
    /** GET /branches/{code}/availability?service=PAO&date=2026-12-07&days=3 */
    public function show(AvailabilityRequest $request, Branch $branch, AvailabilityService $availability): JsonResponse
    {
        abort_unless($branch->is_active, 404);

        $service = Service::query()->where('code', $request->validated('service'))->firstOrFail();
        abort_unless($service->is_active, 404);

        $week = $availability->slotsForRange($branch, $service, $request->validated('date'), (int) ($request->validated('days') ?? 1));

        return response()->json(['data' => collect($week)->map(fn (array $slots, string $date): array => [
            'date' => $date,
            'slots' => array_map(fn (Slot $slot): array => $slot->toArray(), $slots),
        ])->values()]);
    }
}
