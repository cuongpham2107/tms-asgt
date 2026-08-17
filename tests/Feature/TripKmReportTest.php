<?php

use App\Enums\CheckpointType;
use App\Enums\ShiftType;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripKmAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->role = Role::create(['name' => 'driver', 'guard_name' => 'web']);
    $this->driver = User::factory()->create();
    $this->driver->assignRole($this->role);

    $this->admin = User::factory()->create();

    $this->vehicle = Vehicle::create([
        'plate_number' => '29C-12345',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 108000,
    ]);

    $this->shift = DriverShift::create([
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
        'start_km' => 100000,
    ]);

    $this->trip = Trip::create([
        'trip_code' => 'CD-2026-08-17-999',
        'vehicle_id' => $this->vehicle->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'status' => TripStatus::Started,
        'start_km' => 108000,
        'started_at' => now(),
    ]);

    TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'checkpoint_type' => CheckpointType::Started,
        'km_reading' => 108000,
        'occurred_at' => now(),
    ]);
});

test('driver can submit a km discrepancy report without photo', function () {
    Sanctum::actingAs($this->driver);

    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'reported_km' => 100085,
        'note' => 'Tài xế trước gõ nhầm 108.000, thực tế taplo là 100.085',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.reported_km', '100085.0')
        ->assertJsonPath('data.system_km', '108000.0')
        ->assertJsonPath('data.status', 'pending');

    expect(TripKmReport::count())->toBe(1);
    $report = TripKmReport::first();
    expect($report->trip_id)->toBe($this->trip->id)
        ->and((float) $report->reported_km)->toBe(100085.0)
        ->and((float) $report->system_km)->toBe(108000.0)
        ->and($report->status)->toBe(TripKmReportStatus::Pending)
        ->and($report->photo_path)->toBeNull();
});

test('driver can submit a km discrepancy report with photo', function () {
    Storage::fake('public');
    Sanctum::actingAs($this->driver);

    $file = UploadedFile::fake()->image('taplo.jpg');

    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'reported_km' => 100085,
        'note' => 'Đính kèm ảnh chụp taplo',
        'photo' => $file,
    ]);

    $response->assertStatus(201);

    $report = TripKmReport::first();
    expect($report)->not->toBeNull()
        ->and($report->photo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($report->photo_path);
});

test('validates negative reported km', function () {
    Sanctum::actingAs($this->driver);

    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'reported_km' => -10,
    ]);

    $response->assertStatus(422);
});

test('unassigned driver cannot submit km report', function () {
    $otherDriver = User::factory()->create();
    $otherDriver->assignRole($this->role);

    Sanctum::actingAs($otherDriver);

    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'reported_km' => 100085,
    ]);

    $response->assertStatus(403);
});

test('admin can resolve report via TripKmAdjustmentService and recalculate cascade', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 100085,
        'system_km' => 108000,
        'note' => 'Lệch 7915km',
        'status' => 'pending',
    ]);

    app(TripKmAdjustmentService::class)->resolveReport(
        $report,
        100085.0,
        'Đã đối soát ảnh taplo và chấp nhận sửa về 100.085',
        $this->admin->id
    );

    $report->refresh();
    $this->trip->refresh();
    $this->vehicle->refresh();

    expect($report->status)->toBe(TripKmReportStatus::Resolved)
        ->and($report->resolved_by)->toBe($this->admin->id)
        ->and($report->resolved_at)->not->toBeNull()
        ->and($report->admin_note)->toBe('Đã đối soát ảnh taplo và chấp nhận sửa về 100.085');

    // Checkpoint & trip start_km adjusted
    expect((float) $this->trip->start_km)->toBe(100085.0);
    $firstCp = $this->trip->checkpoints()->first();
    expect((float) $firstCp->km_reading)->toBe(100085.0);

    // Vehicle current_mileage safely updated
    expect((float) $this->vehicle->current_mileage)->toBe(100085.0);
});

test('admin can reject report via TripKmAdjustmentService', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 100085,
        'system_km' => 108000,
        'note' => 'Lệch km',
        'status' => 'pending',
    ]);

    app(TripKmAdjustmentService::class)->rejectReport(
        $report,
        'Ảnh taplo mờ không rõ số, từ chối',
        $this->admin->id
    );

    $report->refresh();
    expect($report->status)->toBe(TripKmReportStatus::Rejected)
        ->and($report->resolved_by)->toBe($this->admin->id)
        ->and($report->admin_note)->toBe('Ảnh taplo mờ không rõ số, từ chối');
});
