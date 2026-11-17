<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Xác thực API public tra cứu xe qua header X-Api-Key (mặc định dùng PUBLIC_VEHICLE_API_KEY hoặc APP_KEY).
 */
class ValidatePublicApiKey
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedKey = (string) (config('app.public_vehicle_api_key') ?: config('app.key'));
        $providedKey = trim((string) ($request->header('X-Api-Key') ?: $request->bearerToken() ?: ''));

        if ($expectedKey === '' || $providedKey === '' || ! hash_equals($expectedKey, $providedKey)) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
