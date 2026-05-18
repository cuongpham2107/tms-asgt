<?php

use App\Http\Controllers\MapboxController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Mapbox helper for map matching (server-side)
Route::post('/mapbox/match', [MapboxController::class, 'match']);
