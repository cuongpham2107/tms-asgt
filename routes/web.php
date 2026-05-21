<?php

use App\Http\Controllers\MapboxController;
use Illuminate\Support\Facades\Route;

// Mapbox helper for map matching (server-side)
Route::post('/mapbox/match', [MapboxController::class, 'match']);
