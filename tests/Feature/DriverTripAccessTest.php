<?php

use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('tells a driver in Vietnamese that a trip now belongs to someone else', function (): void {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $oldDriver = User::factory()->create();
    $oldDriver->assignRole('driver');
    $trip = Trip::factory()->create(['status' => TripStatus::Pending]);
    $trip->driver->assignRole('driver');

    Sanctum::actingAs($oldDriver);

    $this->getJson("/api/driver/trips/{$trip->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Chuyến này đã được điều phối cho tài xế khác hoặc không còn thuộc về bạn.');

    Sanctum::actingAs($trip->driver);

    $this->getJson("/api/driver/trips/{$trip->id}")->assertOk();
});
