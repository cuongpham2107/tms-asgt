<?php

use App\Enums\ShiftType;
use App\Enums\TripKmReportStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Livewire\DatabaseNotifications;
use App\Models\DatabaseNotification;
use App\Models\DriverShift;
use App\Models\Trip;
use App\Models\TripKmReport;
use App\Models\User;
use App\Models\Vehicle;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);
    $this->admin = User::factory()->create();
});

test('trip view notification action marks as read and closes modal without deleting the notification', function () {
    $driver = User::factory()->create();
    $driver->assignRole('driver');

    $vehicle = Vehicle::create([
        'plate_number' => '29C-55555',
        'vehicle_type' => VehicleType::Normal,
        'owner' => 'ASGT',
        'is_active' => true,
        'status' => VehicleStatus::On,
        'type' => VehicleOwnerType::Company,
        'current_mileage' => 10000,
    ]);

    $shift = DriverShift::create([
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'shift_type' => ShiftType::Full,
        'start_time' => now(),
    ]);

    $trip = Trip::create([
        'trip_code' => 'CD-2026-11-17-1',
        'vehicle_id' => $vehicle->id,
        'driver_id' => $driver->id,
        'shift_id' => $shift->id,
        'status' => TripStatus::Delivered,
        'start_km' => 10000,
        'started_at' => now()->subHour(),
        'delivered_at' => now(),
    ]);

    $notification = DB::table('notifications')
        ->where('notifiable_id', $this->admin->id)
        ->latest('created_at')
        ->first();

    expect($notification)->not->toBeNull();

    $data = json_decode($notification->data, true);
    $viewAction = collect($data['actions'] ?? [])->firstWhere('name', 'view');

    expect($viewAction)->not->toBeNull()
        ->and($viewAction['label'])->toBe('Xem chi tiết')
        ->and($viewAction['shouldClose'])->toBeFalse()
        ->and($viewAction['shouldMarkAsRead'])->toBeTrue()
        ->and($viewAction['alpineClickHandler'])->toContain('close-modal')
        ->and($viewAction['alpineClickHandler'])->toContain('markAsRead()')
        ->and($viewAction['alpineClickHandler'])->not->toContain('close(');

    // Đảm bảo thông báo báo sai Km cũng áp dụng cùng rule cho nút Xem chi tiết
    TripKmReport::create([
        'trip_id' => $trip->id,
        'driver_id' => $driver->id,
        'vehicle_id' => $vehicle->id,
        'reported_km' => 10090,
        'system_km' => 10000,
        'status' => TripKmReportStatus::Pending,
    ]);

    $kmNotification = DB::table('notifications')
        ->where('notifiable_id', $this->admin->id)
        ->where('data', 'like', '%resolve_km_report%')
        ->first();

    expect($kmNotification)->not->toBeNull();
    $kmViewAction = collect(json_decode($kmNotification->data, true)['actions'] ?? [])->firstWhere('name', 'view');
    expect($kmViewAction['shouldClose'])->toBeFalse()
        ->and($kmViewAction['shouldMarkAsRead'])->toBeTrue()
        ->and($kmViewAction['alpineClickHandler'])->not->toContain('close(');
});

test('database notifications drawer only shows notifications from the last 24 hours and keeps read ones', function () {
    expect(Filament::getPanel('app')->getDatabaseNotificationsLivewireComponent())
        ->toBe(DatabaseNotifications::class);

    $recentId = (string) Str::uuid();
    $expiredId = (string) Str::uuid();

    $payload = Notification::make()
        ->title('Thông báo test')
        ->body('Nội dung')
        ->getDatabaseMessage();

    DatabaseNotification::create([
        'id' => $recentId,
        'type' => \Filament\Notifications\DatabaseNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $this->admin->id,
        'data' => array_merge($payload, ['title' => 'Trong 24h']),
        'created_at' => now()->subHours(23),
        'updated_at' => now()->subHours(23),
    ]);

    DatabaseNotification::create([
        'id' => $expiredId,
        'type' => \Filament\Notifications\DatabaseNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $this->admin->id,
        'data' => array_merge($payload, ['title' => 'Quá 24h']),
        'created_at' => now()->subHours(24)->subMinute(),
        'updated_at' => now()->subHours(24)->subMinute(),
    ]);

    $component = Livewire::actingAs($this->admin)->test(DatabaseNotifications::class);

    /** @var DatabaseNotifications $instance */
    $instance = $component->instance();
    expect($instance->getUnreadNotificationsCount())->toBe(1)
        ->and($instance->getNotifications()->pluck('id')->all())->toBe([$recentId]);

    // Đánh dấu đã đọc: badge chưa đọc về 0 nhưng thông báo vẫn hiển thị trong 24h
    $component->call('markNotificationAsRead', $recentId);

    expect($instance->getUnreadNotificationsCount())->toBe(0)
        ->and($instance->getNotifications()->pluck('id')->all())->toBe([$recentId]);
});

test('database notifications older than 24 hours are pruned hourly', function () {
    $payload = Notification::make()
        ->title('Thông báo prune')
        ->getDatabaseMessage();

    $old = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => \Filament\Notifications\DatabaseNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $this->admin->id,
        'data' => $payload,
        'created_at' => now()->subHours(24)->subMinute(),
        'updated_at' => now()->subHours(24)->subMinute(),
    ]);

    $recent = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => \Filament\Notifications\DatabaseNotification::class,
        'notifiable_type' => User::class,
        'notifiable_id' => $this->admin->id,
        'data' => $payload,
        'created_at' => now()->subHours(23),
        'updated_at' => now()->subHours(23),
    ]);

    $this->artisan('model:prune', ['--model' => [DatabaseNotification::class]])->assertSuccessful();

    expect(DatabaseNotification::find($old->id))->toBeNull()
        ->and(DatabaseNotification::find($recent->id))->not->toBeNull();

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($item) => str_contains($item->command ?? '', 'DatabaseNotification'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});
