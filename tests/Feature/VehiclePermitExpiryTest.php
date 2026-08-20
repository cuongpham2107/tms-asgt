<?php

use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('calculates dangerous goods permit expiry status for vehicles', function () {
    $vehicleValid = Vehicle::create([
        'plate_number' => '51C-111.11',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'dangerous_goods_permit_number' => 'GP-001',
        'dangerous_goods_permit_expiry_date' => now()->addDays(90),
    ]);

    $statusValid = $vehicleValid->getDangerousGoodsPermitStatus();
    expect($statusValid['status'])->toBe('valid');
    expect($statusValid['color'])->toBe('success');
    expect($vehicleValid->hasExpiredDangerousGoodsPermit())->toBeFalse();

    $vehicleExpiring = Vehicle::create([
        'plate_number' => '51C-222.22',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'dangerous_goods_permit_number' => 'GP-002',
        'dangerous_goods_permit_expiry_date' => now()->addDays(10),
    ]);

    $statusExpiring = $vehicleExpiring->getDangerousGoodsPermitStatus();
    expect($statusExpiring['status'])->toBe('expiring_soon');
    expect($statusExpiring['color'])->toBe('warning');
    expect($vehicleExpiring->hasExpiringSoonDangerousGoodsPermit())->toBeTrue();

    $vehicleExpired = Vehicle::create([
        'plate_number' => '51C-333.33',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'dangerous_goods_permit_number' => 'GP-003',
        'dangerous_goods_permit_expiry_date' => now()->subDays(2),
    ]);

    $statusExpired = $vehicleExpired->getDangerousGoodsPermitStatus();
    expect($statusExpired['status'])->toBe('expired');
    expect($statusExpired['color'])->toBe('danger');
    expect($vehicleExpired->hasExpiredDangerousGoodsPermit())->toBeTrue();
});
