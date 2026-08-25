<?php

use App\Enums\OnDutyLocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user filter can query users by station and active status', function () {
    $activeUser = User::factory()->create([
        'name' => 'User Active',
        'station' => OnDutyLocation::Tn,
        'is_active' => true,
    ]);

    $inactiveUser = User::factory()->create([
        'name' => 'User Inactive',
        'station' => OnDutyLocation::Bn,
        'is_active' => false,
    ]);

    expect(User::where('station', OnDutyLocation::Tn)->count())->toBe(1)
        ->and(User::where('is_active', false)->count())->toBe(1);
});
