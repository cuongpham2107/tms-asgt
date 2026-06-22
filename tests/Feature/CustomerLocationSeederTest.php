<?php

use App\Models\Location;
use Database\Seeders\CustomerLocationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

test('customer location seeder runs geocode command and seeds coordinates correctly', function () {
    // 1. Fake the Nominatim geocoding HTTP responses
    Http::fake([
        'nominatim.openstreetmap.org/*' => Http::response([
            [
                'lat' => '10.123456',
                'lon' => '106.654321',
            ],
        ], 200),
    ]);

    // 2. Run the seeder via the artisan test helper
    artisan('db:seed', [
        '--class' => CustomerLocationSeeder::class,
    ]);

    // 3. Verify locations in the database have coordinates successfully seeded
    $location = Location::where('code', 'ACSV')->first();
    expect($location)->not->toBeNull();
    expect((float) $location->lat)->toBe(10.123456);
    expect((float) $location->lng)->toBe(106.654321);
});
