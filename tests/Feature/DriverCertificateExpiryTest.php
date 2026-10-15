<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->driverRole = Role::create([
        'name' => 'driver',
        'guard_name' => 'web',
    ]);
});

test('calculates certificate expiry statuses accurately', function () {
    $driver = User::factory()->create([
        'license_expiry_date' => now()->addDays(60),
        'aviation_security_cert_expiry_date' => now()->addDays(15),
        'dangerous_goods_cert_expiry_date' => now()->subDays(5),
    ]);

    $licenseStatus = $driver->getLicenseExpiryStatus();
    expect($licenseStatus['status'])->toBe('valid');
    expect($licenseStatus['color'])->toBe('success');

    $anhkStatus = $driver->getAviationSecurityCertStatus();
    expect($anhkStatus['status'])->toBe('expiring_soon');
    expect($anhkStatus['color'])->toBe('warning');

    $dgStatus = $driver->getDangerousGoodsCertStatus();
    expect($dgStatus['status'])->toBe('expired');
    expect($dgStatus['color'])->toBe('danger');

    expect($driver->hasExpiredCertificates())->toBeTrue();
    expect($driver->hasExpiringSoonCertificates())->toBeTrue();
});

test('handles missing certificates gracefully', function () {
    $driver = User::factory()->create([
        'license_expiry_date' => null,
        'aviation_security_cert_expiry_date' => null,
        'dangerous_goods_cert_expiry_date' => null,
    ]);

    expect($driver->getLicenseExpiryStatus()['status'])->toBe('missing');
    expect($driver->getAviationSecurityCertStatus()['status'])->toBe('missing');
    expect($driver->getDangerousGoodsCertStatus()['status'])->toBe('missing');
    expect($driver->hasExpiredCertificates())->toBeFalse();
    expect($driver->hasExpiringSoonCertificates())->toBeFalse();
});

test('stores multiple certificate images as array', function () {
    $driver = User::factory()->create([
        'license_image' => ['licenses/front.jpg', 'licenses/back.jpg'],
        'aviation_security_cert_image' => ['certificates/aviation_security/front.jpg', 'certificates/aviation_security/back.jpg'],
        'dangerous_goods_cert_image' => ['certificates/dangerous_goods/front.jpg', 'certificates/dangerous_goods/back.jpg'],
    ]);

    $driver->refresh();

    expect($driver->license_image)->toBe(['licenses/front.jpg', 'licenses/back.jpg'])
        ->and($driver->aviation_security_cert_image)->toBe(['certificates/aviation_security/front.jpg', 'certificates/aviation_security/back.jpg'])
        ->and($driver->dangerous_goods_cert_image)->toBe(['certificates/dangerous_goods/front.jpg', 'certificates/dangerous_goods/back.jpg']);
});
