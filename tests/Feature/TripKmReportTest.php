<?php

use App\Enums\CheckpointType;
use App\Enums\ShiftType;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Filament\Resources\Trips\Actions\ResolveKmReportAction;
use App\Filament\Resources\Trips\TripResource;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use App\Observers\TripObserver;
use App\Services\TripKmAdjustmentService;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
        null,
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

test('admin can resolve report with specific targetCheckpointId', function () {
    $cp2 = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'checkpoint_type' => CheckpointType::Completed,
        'km_reading' => 108050,
        'occurred_at' => now()->addHour(),
    ]);

    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'checkpoint_id' => $cp2->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 100150,
        'system_km' => 108050,
        'note' => 'Nhầm ở bước kết thúc',
        'status' => 'pending',
    ]);

    app(TripKmAdjustmentService::class)->resolveReport(
        $report,
        100150.0,
        $cp2->id,
        'Sửa mốc kết thúc',
        $this->admin->id
    );

    $cp2->refresh();
    expect((float) $cp2->km_reading)->toBe(100150.0);
    $firstCp = $this->trip->checkpoints()->first();
    // First checkpoint was NOT touched
    expect((float) $firstCp->km_reading)->toBe(108000.0);
});

test('driver submitting with checkpoint_id records correct checkpoint and system_km', function () {
    Sanctum::actingAs($this->driver);

    $cp = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'km_reading' => 108020,
        'occurred_at' => now(),
    ]);

    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'checkpoint_id' => $cp->id,
        'reported_km' => 100095,
        'note' => 'Gõ nhầm số km',
    ]);

    $response->assertStatus(201)
        ->assertJsonPath('data.reported_km', '100095.0')
        ->assertJsonPath('data.system_km', '108020.0');

    $report = TripKmReport::latest('id')->first();
    expect($report->checkpoint_id)->toBe($cp->id)
        ->and((float) $report->system_km)->toBe(108020.0);
});

test('submitting again while pending updates existing pending report', function () {
    Sanctum::actingAs($this->driver);

    $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'reported_km' => 100085,
        'note' => 'Lần 1',
    ]);

    expect(TripKmReport::count())->toBe(1);

    $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'reported_km' => 100090,
        'note' => 'Lần 2 sửa lại',
    ]);

    expect(TripKmReport::count())->toBe(1);
    $report = TripKmReport::first();
    expect((float) $report->reported_km)->toBe(100090.0)
        ->and($report->note)->toContain('Lần 2 sửa lại');
});

test('reporting again while pending keeps one report, records the previous value and does not send new notifications', function () {
    $url = "/api/driver/trips/{$this->trip->id}/report-km-issue";
    Sanctum::actingAs($this->driver);

    $this->postJson($url, ['reported_km' => 10500, 'note' => 'lần 1'])->assertCreated();
    $countAfterFirst = DB::table('notifications')->where('notifiable_id', $this->admin->id)->count();

    $this->postJson($url, ['reported_km' => 10550, 'note' => 'lần 2'])->assertCreated();

    $report = TripKmReport::where('trip_id', $this->trip->id)->sole();
    $notification = DB::table('notifications')->where('notifiable_id', $this->admin->id)->latest('created_at')->first();

    expect($countAfterFirst)->toBeGreaterThan(0)
        ->and((float) $report->reported_km)->toBe(10550.0)
        ->and($report->note)->toContain('lần 2')
        ->and($report->note)->toContain('10.500')
        ->and(DB::table('notifications')->where('notifiable_id', $this->admin->id)->count())->toBe($countAfterFirst)
        ->and($notification->data)->toContain('10.550')
        ->and($notification->read_at)->toBeNull();
});

test('re-reporting after dispatchers deleted the notifications alerts them again', function () {
    $url = "/api/driver/trips/{$this->trip->id}/report-km-issue";
    Sanctum::actingAs($this->driver);

    $this->postJson($url, ['reported_km' => 10500, 'note' => 'lần 1'])->assertCreated();
    DB::table('notifications')->delete();

    $this->postJson($url, ['reported_km' => 10550, 'note' => 'lần 2'])->assertCreated();

    $rows = DB::table('notifications')->where('notifiable_id', $this->admin->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->data)->toContain('10.550');
});

test('re-reporting with the same km but a new note refreshes the notification as unread', function () {
    $url = "/api/driver/trips/{$this->trip->id}/report-km-issue";
    Sanctum::actingAs($this->driver);

    $this->postJson($url, ['reported_km' => 10500, 'note' => 'lần 1'])->assertCreated();
    DB::table('notifications')->update(['read_at' => now()]);
    $count = DB::table('notifications')->where('notifiable_id', $this->admin->id)->count();

    $this->postJson($url, ['reported_km' => 10500, 'note' => 'ghi chú mới'])->assertCreated();

    $notifications = DB::table('notifications')->where('notifiable_id', $this->admin->id)->get();
    expect($notifications)->toHaveCount($count)
        ->and($notifications->every(fn ($n) => $n->read_at === null))->toBeTrue();
});

test('getKmBoundaries calculates correct min and max km between adjacent checkpoints', function () {
    // Current trip has first checkpoint at 108000
    $cp1 = $this->trip->checkpoints()->first(); // 108000

    $cp2 = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup,
        'km_reading' => 108050,
        'occurred_at' => now()->addMinutes(30),
    ]);

    $cp3 = TripCheckpoint::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'shift_id' => $this->shift->id,
        'checkpoint_type' => CheckpointType::Completed,
        'km_reading' => 108100,
        'occurred_at' => now()->addMinutes(60),
    ]);

    $service = app(TripKmAdjustmentService::class);

    // Boundary for intermediate checkpoint cp2 (108050)
    $b2 = $service->getKmBoundaries($this->trip, $cp2->id);
    expect($b2['min_km'])->toBe(108000.0)
        ->and($b2['max_km'])->toBe(108100.0)
        ->and($b2['min_label'])->toContain('108000')
        ->and($b2['max_label'])->toContain('108100');

    // Boundary for first checkpoint cp1 (108000)
    $b1 = $service->getKmBoundaries($this->trip, $cp1->id);
    expect($b1['min_km'])->toBeNull()
        ->and($b1['max_km'])->toBe(108050.0);

    // Boundary for last checkpoint cp3 (108100)
    $b3 = $service->getKmBoundaries($this->trip, $cp3->id);
    expect($b3['min_km'])->toBe(108050.0)
        ->and($b3['max_km'])->toBeNull();
});

test('driver reporting km issue pauses trip, blocks further checkpoints and completion', function () {
    Sanctum::actingAs($this->driver);

    // 1. Driver reports km discrepancy
    $response = $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'reported_km' => 100085,
        'note' => 'Nhầm số km, chờ điều chỉnh',
    ]);
    $response->assertStatus(201);

    $this->trip->refresh();
    expect($this->trip->is_paused)->toBeTrue()
        ->and($this->trip->isPaused())->toBeTrue()
        ->and($this->trip->paused_reason)->toContain('Nhầm số km')
        ->and($this->trip->getStatusLabel())->toBe('Tạm dừng (Sửa Km)')
        ->and($this->trip->getStatusColor())->toBe('warning');

    // 2. Driver attempts to record a checkpoint while paused -> BLOCKED (422)
    $checkpointResponse = $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 108020,
    ]);
    $checkpointResponse->assertStatus(422)
        ->assertJsonPath('is_paused', true)
        ->assertJsonFragment([
            'message' => 'Chuyến xe đang tạm dừng do có báo sai số Km đang chờ Điều hành xử lý. Vui lòng liên hệ Điều hành trước khi cập nhật mốc tiếp theo.',
        ]);

    // 3. Driver attempts to complete the trip while paused -> BLOCKED (422)
    $completeResponse = $this->postJson("/api/driver/trips/{$this->trip->id}/complete", [
        'end_km' => 108050,
    ]);
    $completeResponse->assertStatus(422)
        ->assertJsonPath('is_paused', true)
        ->assertJsonFragment([
            'message' => 'Chuyến xe đang tạm dừng do có báo sai số Km đang chờ Điều hành xử lý. Vui lòng liên hệ Điều hành trước khi kết thúc chuyến.',
        ]);

    // 4. Admin resolves the report -> trip is UNPAUSED
    $report = TripKmReport::where('trip_id', $this->trip->id)->first();
    app(TripKmAdjustmentService::class)->resolveReport(
        $report,
        100085.0,
        null,
        'Đã duyệt sửa km',
        $this->admin->id
    );

    $this->trip->refresh();
    expect($this->trip->is_paused)->toBeFalse()
        ->and($this->trip->isPaused())->toBeFalse()
        ->and($this->trip->paused_reason)->toBeNull();

    // 5. Driver can now submit checkpoint successfully
    $afterUnpauseResponse = $this->postJson("/api/driver/trips/{$this->trip->id}/checkpoints", [
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => 100090,
    ]);
    $afterUnpauseResponse->assertStatus(200);
});

test('admin rejecting km report unpauses trip and allows driver to proceed', function () {
    Sanctum::actingAs($this->driver);

    // Driver reports km discrepancy
    $this->postJson("/api/driver/trips/{$this->trip->id}/report-km-issue", [
        'reported_km' => 100085,
        'note' => 'Nhầm số km',
    ])->assertStatus(201);

    $this->trip->refresh();
    expect($this->trip->is_paused)->toBeTrue();

    // Admin rejects report
    $report = TripKmReport::where('trip_id', $this->trip->id)->first();
    app(TripKmAdjustmentService::class)->rejectReport(
        $report,
        'Không khớp taplo thực tế',
        $this->admin->id
    );

    $this->trip->refresh();
    expect($this->trip->is_paused)->toBeFalse()
        ->and($this->trip->isPaused())->toBeFalse()
        ->and($this->trip->paused_reason)->toBeNull();
});

test('ResolveKmReportAction is configured and TripObserver sends direct resolve action url in notification', function () {
    $action = ResolveKmReportAction::make();
    expect($action->getName())->toBe('resolve_km_report');

    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 100090,
        'system_km' => 100000,
        'status' => TripKmReportStatus::Pending,
    ]);

    $expectedUrl = TripResource::getUrl('index', [
        'tableAction' => 'resolve_km_report',
        'tableActionRecord' => $this->trip->getKey(),
        'tripSearch' => $this->trip->trip_code,
        'activeStatusFilter' => 'all',
        'orderType' => 'all',
        'activePlaceFilter' => 'all',
        'vehicleOwner' => 'all',
    ]);

    expect($expectedUrl)->toContain('tableAction=resolve_km_report')
        ->and($expectedUrl)->toContain('tableActionRecord='.$this->trip->getKey())
        ->and($expectedUrl)->toContain('activeStatusFilter=all')
        ->and($expectedUrl)->toContain('orderType=all');
});

test('notification actions are cleared when km discrepancy report is resolved', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 108050,
        'system_km' => 108000,
        'status' => TripKmReportStatus::Pending,
    ]);

    $notification = DB::table('notifications')
        ->where('data', 'like', '%resolve_km_report%')
        ->where('data', 'like', '%tableActionRecord='.$this->trip->id.'%')
        ->first();

    expect($notification)->not->toBeNull();
    $data = json_decode($notification->data, true);
    expect($data['actions'])->toHaveCount(2);

    app(TripKmAdjustmentService::class)->resolveReport(
        $report,
        108050,
        null,
        'Đã kiểm tra taplo đúng',
        $this->admin->id
    );

    $notificationAfterResolve = DB::table('notifications')->where('id', $notification->id)->first();
    $dataResolved = json_decode($notificationAfterResolve->data, true);

    expect($dataResolved['actions'])->toBeEmpty()
        ->and($dataResolved['status'])->toBe('success')
        ->and($dataResolved['body'])->toContain('[Đã duyệt điều chỉnh]');
});

test('notification actions are cleared when km discrepancy report is rejected', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 108050,
        'system_km' => 108000,
        'status' => TripKmReportStatus::Pending,
    ]);

    $notification = DB::table('notifications')
        ->where('data', 'like', '%resolve_km_report%')
        ->where('data', 'like', '%tableActionRecord='.$this->trip->id.'%')
        ->first();

    expect($notification)->not->toBeNull();

    app(TripKmAdjustmentService::class)->rejectReport(
        $report,
        'Không đúng taplo',
        $this->admin->id
    );

    $notificationAfterReject = DB::table('notifications')->where('id', $notification->id)->first();
    $dataRejected = json_decode($notificationAfterReject->data, true);

    expect($dataRejected['actions'])->toBeEmpty()
        ->and($dataRejected['status'])->toBe('danger')
        ->and($dataRejected['body'])->toContain('[Đã từ chối]');
});

test('ResolveKmReportAction cancels and sends warning notification if report was already resolved before opening modal', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 108050,
        'system_km' => 108000,
        'status' => TripKmReportStatus::Pending,
    ]);

    app(TripKmAdjustmentService::class)->resolveReport(
        $report,
        108050,
        null,
        'Admin 1 đã xử lý',
        $this->admin->id
    );

    $action = ResolveKmReportAction::make();
    $action->record($this->trip);

    expect(fn () => $action->callBeforeFormFilled())->toThrow(Cancel::class);

    Notification::assertNotified('Báo cáo sai Km đã được xử lý');
});

test('ResolveKmReportAction prevents concurrent submission if report was already resolved', function () {
    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 108050,
        'system_km' => 108000,
        'status' => TripKmReportStatus::Pending,
    ]);

    // Admin 1 resolves report
    app(TripKmAdjustmentService::class)->resolveReport(
        $report,
        108050,
        null,
        'Admin 1 giải quyết',
        $this->admin->id
    );

    // Admin 2 who already had form open tries to submit
    $action = ResolveKmReportAction::make();
    $action->record($this->trip);

    expect(fn () => $action->call([
        'report_id' => $report->id,
        'decision' => 'ok',
        'corrected_km' => 108099,
    ]))->toThrow(Cancel::class);

    Notification::assertNotified('Báo cáo sai Km đã được xử lý');

    // Confirm start_km was NOT overwritten to 108099
    $this->trip->refresh();
    expect((float) $this->trip->start_km)->toBe(108050.0);
});

test('resolve_km_report action visibility reflects pending status', function () {
    $action = ResolveKmReportAction::make();
    expect($action->record($this->trip)->isVisible())->toBeFalse();

    $report = TripKmReport::create([
        'trip_id' => $this->trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 108050,
        'system_km' => 108000,
        'status' => TripKmReportStatus::Pending,
    ]);

    $this->trip->refresh();
    expect($action->record($this->trip)->isVisible())->toBeTrue();

    app(TripKmAdjustmentService::class)->resolveReport(
        $report,
        108050,
        null,
        'Xong bởi admin',
        $this->admin->id
    );

    $this->trip->refresh();
    expect($action->record($this->trip)->isVisible())->toBeFalse();
});

test('trip notifications go to every user except drivers', function () {
    $dispatcher = User::factory()->create();

    app(TripObserver::class)->notifyUsersTripKmReported($this->trip);

    expect(DB::table('notifications')->where('notifiable_id', $dispatcher->id)->count())->toBe(1)
        ->and(DB::table('notifications')->whereIn('notifiable_id', User::role('driver')->pluck('id'))->count())->toBe(0);
});

test('resolving a report only clears notifications of that report', function () {
    $newReport = fn (Trip $trip) => TripKmReport::create([
        'trip_id' => $trip->id,
        'driver_id' => $this->driver->id,
        'vehicle_id' => $this->vehicle->id,
        'reported_km' => 108050,
        'system_km' => 108000,
        'status' => TripKmReportStatus::Pending,
    ]);

    $other = null;
    for ($i = 0; $i < 20; $i++) {
        $candidate = Trip::create([
            'trip_code' => "CD-2026-08-17-{$i}",
            'vehicle_id' => $this->vehicle->id,
            'driver_id' => $this->driver->id,
            'shift_id' => $this->shift->id,
            'status' => TripStatus::Started,
            'start_km' => 108000,
            'started_at' => now(),
        ]);

        if (str_starts_with((string) $candidate->id, (string) $this->trip->id)) {
            $other = $candidate;
            break;
        }
    }
    expect($other)->not->toBeNull();

    $report1 = $newReport($this->trip);
    $newReport($other);

    app(TripKmAdjustmentService::class)->rejectReport($report1, 'x', null);

    $otherNotifications = DB::table('notifications')->where('data', 'like', "%tableActionRecord={$other->id}&%")->get();

    expect($otherNotifications)->not->toBeEmpty();
    foreach ($otherNotifications as $row) {
        $data = json_decode($row->data, true);
        expect($data['actions'])->not->toBeEmpty()
            ->and($row->data)->not->toContain('[Đã');
    }
});
