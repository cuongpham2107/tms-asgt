<?php

use App\Jobs\SyncEupGpsJob;
use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use App\Services\EupGpsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('eup sync stores one track point per known vehicle', function () {
    Vehicle::factory()->create(['plate_number' => '29C-111.11']);
    Vehicle::factory()->create(['plate_number' => '29C-222.22']);

    Http::fake(['*' => Http::response(['result' => [
        ['VehicleNo' => '29C-111.11', 'Latitude' => 10.8, 'Longitude' => 106.6, 'Speed' => 30, 'Direction' => 90],
        ['VehicleNo' => '29C-222.22', 'Latitude' => 21.0, 'Longitude' => 105.8, 'Speed' => 0, 'Direction' => 0],
        ['VehicleNo' => 'UNKNOWN', 'Latitude' => 1, 'Longitude' => 1],
    ]])]);

    (new SyncEupGpsJob)->handle(app(EupGpsService::class));

    expect(VehicleGpsPoint::where('source', VehicleGpsPoint::SOURCE_EUP)->count())->toBe(2);
});

test('the manual gps sync route requires login', function () {
    $this->get('/gps-sync')->assertRedirect();
});
