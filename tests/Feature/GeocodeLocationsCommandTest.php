<?php

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

test('geocode command simplifies complex street address and updates coordinates successfully', function () {
    // 1. Create a location with a complex detailed address
    $location = Location::create([
        'code' => 'TEST-COLINH',
        'name' => 'Cổ Linh Office',
        'address' => 'Số 28, ngõ 36 đường Cổ Linh, tổ 7, Long Biên, TP Hà Nội',
        'is_active' => true,
    ]);

    // 2. Mock Nominatim API. We expect it to be called for the simplified query:
    // "đường Cổ Linh, Long Biên, TP Hà Nội, Vietnam"
    Http::fake([
        'nominatim.openstreetmap.org/search?*' => function ($request) {
            $queryParams = [];
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $queryParams);

            // Check that the query is simplified
            if (isset($queryParams['q']) && str_contains($queryParams['q'], 'đường Cổ Linh')) {
                return Http::response([
                    [
                        'lat' => '21.0263',
                        'lon' => '105.8978',
                    ],
                ], 200);
            }

            return Http::response([], 200);
        },
    ]);

    // 3. Run the geocode Artisan command
    artisan('locations:geocode');

    // 4. Assert database is updated with geocoded coordinates
    $location->refresh();
    expect((float) $location->lat)->toBe(21.0263);
    expect((float) $location->lng)->toBe(105.8978);
});
