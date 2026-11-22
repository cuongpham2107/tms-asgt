<?php

use App\Ai\Tools\CheckTripKm;
use App\Enums\TripStatus;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * Công cụ "Kiểm tra km" của Trợ lý dữ liệu: tách lỗi km do điều phối sửa / do tài xế nhập, kèm link mở form sửa chuyến.
 */
beforeEach(function () {
    Carbon::setTestNow('2026-11-21 10:00:00');

    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'ViewAny:Trip', 'guard_name' => 'web']);

    $this->staff = User::factory()->create(['name' => 'Điều phối A', 'is_active' => true]);
    $this->staff->givePermissionTo('ViewAny:Trip');

    $this->driver = User::factory()->create(['name' => 'Tài xế B', 'is_active' => true]);
    $this->driver->assignRole('driver');

    $this->vehicle = Vehicle::factory()->create(['plate_number' => '20C18325']);

    $this->makeTrip = fn (string $code, string $start, float $startKm, float $endKm, array $attributes = []): Trip => Trip::factory()->create([
        'trip_code' => $code,
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'status' => TripStatus::Completed,
        'started_at' => Carbon::parse($start),
        'completed_at' => Carbon::parse($start)->addHours(2),
        'start_km' => $startKm,
        'end_km' => $endKm,
        'total_km' => $endKm - $startKm,
        ...$attributes,
    ]);

    $this->appCheckpoint = fn (Trip $trip, string $type, string $at, float $km): TripCheckpoint => TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => $type,
        'occurred_at' => Carbon::parse($at),
        'km_reading' => $km,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'gps_lat' => 21.2,
        'gps_lng' => 105.8,
    ]);

    $this->runTool = fn (array $args = []): string => (string) (new CheckTripKm)->handle(new Request([
        'date_from' => '2026-11-01',
        'date_to' => '2026-11-21',
        ...$args,
    ]));
});

test('lists a staff km typo under the dispatch section with a link that opens the trip edit form', function () {
    ($this->makeTrip)('CD-OK-1', '2026-11-19 08:00', 322000, 322084);
    $trip = ($this->makeTrip)('CD-TYPO-1', '2026-11-19 19:20', 322084, 322168);

    // Điều phối gõ thiếu một chữ số khi sửa km bắt đầu.
    $this->actingAs($this->staff);
    $trip->update(['start_km' => 32084, 'total_km' => 290084]);

    $output = ($this->runTool)();

    expect($output)
        ->toContain('Do điều phối sửa')
        ->toContain('CD-TYPO-1')
        ->toContain('Điều phối A')
        ->toContain('tableAction=edit')
        ->toContain('tableActionRecord='.$trip->id)
        // Chuyến trước chỉ được nhắc trong phần chi tiết, không có dòng lỗi riêng.
        ->not->toContain('[CD-OK-1]');
});

test('lists back-to-back checkpoints entered on the driver app under the driver section', function () {
    $trip = ($this->makeTrip)('CD-BATCH-1', '2026-11-18 08:00', 207822, 207967);
    ($this->appCheckpoint)($trip, 'started', '2026-11-18 08:01', 207822);
    ($this->appCheckpoint)($trip, 'arrived_pickup', '2026-11-18 08:01', 207952);
    ($this->appCheckpoint)($trip, 'end', '2026-11-18 09:03', 207967);

    $this->actingAs($this->staff);
    $output = ($this->runTool)();

    expect($output)
        ->toContain('Do tài xế nhập')
        ->toContain('CD-BATCH-1')
        ->toContain('Tài xế B')
        ->toContain('+130 km');
});

test('ignores back-to-back checkpoints on empty-run trips', function () {
    $trip = ($this->makeTrip)('CD-EMPTY-1', '2026-11-20 08:28', 426276, 426325, ['is_empty_run' => true]);
    ($this->appCheckpoint)($trip, 'started', '2026-11-20 08:28', 426276);
    ($this->appCheckpoint)($trip, 'end', '2026-11-20 08:28', 426325);

    $this->actingAs($this->staff);
    $output = ($this->runTool)();

    expect($output)->toContain('Không phát hiện')->not->toContain('CD-EMPTY-1');
});

test('flags a trip that starts below the previous trip end km on the same vehicle', function () {
    ($this->makeTrip)('CD-PREV-1', '2026-11-17 08:00', 439564, 439620);
    ($this->makeTrip)('CD-NEXT-1', '2026-11-17 11:30', 439600, 439624);

    $this->actingAs($this->staff);
    $output = ($this->runTool)();

    expect($output)
        ->toContain('CD-NEXT-1')
        ->toContain('CD-PREV-1')
        ->toContain('-20 km');
});

test('warns when a completed trip runs more km than usual', function () {
    ($this->makeTrip)('CD-LONG-1', '2026-11-16 08:00', 100000, 100245);
    ($this->makeTrip)('CD-NORMAL-1', '2026-11-16 12:00', 100245, 100400);

    $this->actingAs($this->staff);
    $output = ($this->runTool)();

    expect($output)
        ->toContain('Km chuyến tăng quá nhiều')
        ->toContain('[CD-LONG-1]')
        ->toContain('+245 km trong 2,0 giờ')
        ->not->toContain('[CD-NORMAL-1]');
});

test('reports no issues for clean km data', function () {
    $trip = ($this->makeTrip)('CD-CLEAN-1', '2026-11-18 08:00', 1000, 1040);
    ($this->appCheckpoint)($trip, 'started', '2026-11-18 08:00', 1000);
    ($this->appCheckpoint)($trip, 'arrived_pickup', '2026-11-18 08:30', 1020);
    ($this->appCheckpoint)($trip, 'end', '2026-11-18 10:00', 1040);

    $this->actingAs($this->staff);
    $output = ($this->runTool)();

    expect($output)->toContain('Không phát hiện')->not->toContain('CD-CLEAN-1');
});

test('denies users without trip view permission', function () {
    $this->actingAs(User::factory()->create(['is_active' => true]));

    expect(($this->runTool)())->toContain('không có quyền');
});
