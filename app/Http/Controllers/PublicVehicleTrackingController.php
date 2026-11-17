<?php

namespace App\Http\Controllers;

use App\Models\Vehicle;
use App\Services\Vehicle\PublicVehicleLookupService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;

/**
 * Trang xem vị trí GPS hiện tại của một xe trên bản đồ công khai (URL ký thời hạn).
 */
class PublicVehicleTrackingController extends Controller
{
    public function __construct(
        private readonly PublicVehicleLookupService $lookupService,
    ) {}

    public function show(Request $request, Vehicle $vehicle): View
    {
        $expiresAt = $request->has('expires')
            ? Carbon::createFromTimestamp($request->integer('expires'), config('app.timezone'))
            : now()->addHours(24);

        $positionUrl = URL::temporarySignedRoute(
            'vehicles.public-tracking.position',
            $expiresAt,
            ['vehicle' => $vehicle],
        );

        return view('public.vehicle-tracking', [
            'vehicle' => $vehicle,
            'positionUrl' => $positionUrl,
            'initialPosition' => $this->lookupService->positionPayload($vehicle),
            'linkExpiresAt' => $expiresAt,
        ]);
    }

    public function position(Vehicle $vehicle): JsonResponse
    {
        return response()
            ->json($this->lookupService->positionPayload($vehicle))
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
