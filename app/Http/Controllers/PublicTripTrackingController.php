<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Services\Trip\TripTrackingMapService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Trang theo dõi chuyến dành cho khách hàng.
 *
 * Cả 2 endpoint đều nằm sau middleware `signed`, nên URL chỉ dùng được
 * với đúng chuyến và còn trong thời hạn do điều hành tạo.
 */
class PublicTripTrackingController extends Controller
{
    public function __construct(
        private readonly TripTrackingMapService $mapService,
    ) {}

    public function show(Request $request, Trip $trip): View
    {
        $trip->loadMissing([
            'vehicle',
            'driver',
            'startLocation',
            'endLocation',
            'orders.pickupLocation',
            'orders.deliveryPoints.location',
        ]);

        $expiresAt = $request->has('expires')
            ? Carbon::createFromTimestamp($request->integer('expires'), config('app.timezone'))
            : now()->addMinutes(30);

        $positionUrl = URL::temporarySignedRoute(
            'trips.public-tracking.position',
            $expiresAt,
            ['trip' => $trip],
        );

        return view('public.trip-tracking', [
            'trip' => $trip,
            'map' => $this->mapService->buildMapData($trip),
            'positionUrl' => $positionUrl,
            'initialPosition' => $this->mapService->positionPayload($trip),
            'linkExpiresAt' => $expiresAt,
        ]);
    }

    public function position(Trip $trip): JsonResponse
    {
        return response()
            ->json($this->mapService->positionPayload($trip))
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
