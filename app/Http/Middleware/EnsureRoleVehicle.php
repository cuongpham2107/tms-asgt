<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRoleVehicle
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'hasRole') || ! $user->hasRole('driver')) {
            return response()->json(['message' => 'Forbidden. Driver role required.'], 403);
        }

        return $next($request);
    }
}
