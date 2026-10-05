<?php

use App\Models\User;
use Database\Seeders\DriverImportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('imported drivers get the driver role even when the role does not exist yet', function () {
    $this->seed(DriverImportSeeder::class);

    expect(User::role('driver')->count())
        ->toBeGreaterThan(0)
        ->toBe(User::where('email', 'like', '%@tms.local')->count());
});
