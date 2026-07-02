<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    /**
     * Get a paginated list of locations with search and limit.
     */
    public function index(Request $request): JsonResponse
    {
        $search = $request->query('search');
        $area_id = $request->query('area_id');
        $limit = min((int) $request->query('limit', $request->query('per_page', 15)), 100);
        if ($limit <= 0) {
            $limit = 15;
        }

        $query = Location::query()
            ->with('area')
            ->where('is_active', true);

        if (filled($search)) {
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");

            });
        }
        if (filled($area_id)) {
            $query->where('area_id', $area_id);
        }
        $locations = $query->paginate($limit);

        return response()->json($locations);
    }
}
