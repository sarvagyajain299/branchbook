<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchResource;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BranchController extends Controller
{
    /** GET /branches?city=Dubai&lat=25.08&lng=55.14  (lat+lng sort by distance, nearest first) */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'city' => ['nullable', 'string', 'max:60'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
        ]);

        $query = Branch::query()->where('is_active', true);

        if (isset($validated['city'])) {
            $query->where('city', $validated['city']);
        }

        if (isset($validated['lat'], $validated['lng'])) {
            // Haversine: great-circle distance between two points on a sphere (Earth radius 6371 km).
            // Accurate to about 0.5% (Earth is slightly flattened), far better than needed for "nearest branch".
            $query->select('branches.*')->selectRaw(
                'ROUND(6371 * 2 * ASIN(SQRT(POWER(SIN(RADIANS(latitude - ?) / 2), 2) + COS(RADIANS(?)) * COS(RADIANS(latitude)) * POWER(SIN(RADIANS(longitude - ?) / 2), 2))), 2) AS distance_km',
                [$validated['lat'], $validated['lat'], $validated['lng']],
            )->orderBy('distance_km');
        } else {
            $query->orderBy('city')->orderBy('name');
        }

        return BranchResource::collection($query->paginate(20)->withQueryString());
    }
}
