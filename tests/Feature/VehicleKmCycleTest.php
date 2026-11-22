<?php

use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Pages\VehicleKmCycleReport;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleKmCycle;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-23 12:00:00'));

    $this->leased = Vehicle::factory()->create(['plate_number' => '29C-LEASE', 'owner' => 'HMA', 'type' => VehicleOwnerType::Company, 'current_mileage' => 13500, 'monthly_start_km' => 10000]);
    $this->asgt = Vehicle::factory()->create(['plate_number' => '29C-ASGT', 'owner' => 'ASGT', 'type' => VehicleOwnerType::Company, 'current_mileage' => 25000, 'monthly_start_km' => 20000]);
    $this->running = Vehicle::factory()->create(['plate_number' => '29C-RUN', 'owner' => 'HMA', 'type' => VehicleOwnerType::Company, 'current_mileage' => 52800, 'monthly_start_km' => 50000]);
    Trip::factory()->create(['vehicle_id' => $this->running->id, 'status' => TripStatus::Delivering, 'start_km' => 52500, 'completed_at' => null]);
});

it('records the closing cycle with km over before resetting the monthly start km', function (): void {
    $this->artisan('vehicles:snapshot-monthly-km')->assertSuccessful();

    $leased = VehicleKmCycle::firstWhere('vehicle_id', $this->leased->id);
    expect($leased)
        ->start_km->toEqual(10000.0)
        ->end_km->toEqual(13500.0)
        ->km_run->toEqual(3500.0)
        ->tracks_km_over->toBeTrue()
        ->over_km->toEqual(500.0)
        ->over_fee->toBe(5_000_000)
        ->vehicle_owner->toBe('HMA')
        ->and($leased->cycle_start->toDateTimeString())->toBe('2026-10-23 12:00:00')
        ->and($leased->cycle_end->toDateTimeString())->toBe('2026-11-23 12:00:00');

    expect(VehicleKmCycle::firstWhere('vehicle_id', $this->asgt->id))
        ->km_run->toEqual(5000.0)
        ->tracks_km_over->toBeFalse()
        ->over_km->toEqual(0.0)
        ->over_fee->toBe(0);

    // Xe đang chạy chuyến: cuối kỳ là km bắt đầu chuyến, cả chuyến tính sang kỳ sau
    expect(VehicleKmCycle::firstWhere('vehicle_id', $this->running->id))
        ->end_km->toEqual(52500.0)
        ->km_run->toEqual(2500.0)
        ->over_km->toEqual(0.0);

    expect($this->leased->fresh()->monthly_start_km)->toEqual(13500.0)
        ->and($this->running->fresh()->monthly_start_km)->toEqual(52500.0);
});

it('does not record the same cycle twice when the snapshot runs again that day', function (): void {
    $this->artisan('vehicles:snapshot-monthly-km')->assertSuccessful();
    $this->travel(2)->hours();
    $this->artisan('vehicles:snapshot-monthly-km')->assertSuccessful();

    expect(VehicleKmCycle::where('vehicle_id', $this->leased->id)->count())->toBe(1)
        ->and(VehicleKmCycle::firstWhere('vehicle_id', $this->leased->id)->km_run)->toEqual(3500.0);
});

it('lists the latest cycle for km over vehicles and exports it', function (): void {
    Gate::before(fn () => true);
    $this->actingAs(User::factory()->create());
    $this->artisan('vehicles:snapshot-monthly-km')->assertSuccessful();

    $leasedCycle = VehicleKmCycle::firstWhere('vehicle_id', $this->leased->id);
    $asgtCycle = VehicleKmCycle::firstWhere('vehicle_id', $this->asgt->id);

    Livewire::test(VehicleKmCycleReport::class)
        ->loadTable()
        ->assertSee('1 xe vượt')
        ->assertSee('5.000.000')
        ->assertCanSeeTableRecords([$leasedCycle])
        ->assertCanNotSeeTableRecords([$asgtCycle])
        ->callTableAction('exportExcel')
        ->assertFileDownloaded('Chot-KM-over-20261123.xlsx');
});

it('stores the km over currently on the vehicles as this cycle without resetting the start km', function (): void {
    $this->travelTo(Carbon::parse('2026-11-22 15:30:00'));

    $this->artisan('vehicles:record-km-cycle', ['--dry-run' => true])->assertSuccessful();
    expect(VehicleKmCycle::count())->toBe(0);

    $this->artisan('vehicles:record-km-cycle')->assertSuccessful();

    $leased = VehicleKmCycle::firstWhere('vehicle_id', $this->leased->id);
    expect($leased)
        ->start_km->toEqual(10000.0)
        ->end_km->toEqual(13500.0)
        ->km_run->toEqual(3500.0)
        ->over_fee->toBe(5_000_000)
        ->and($leased->cycle_start->toDateTimeString())->toBe('2026-10-23 12:00:00')
        ->and($leased->cycle_end->toDateTimeString())->toBe('2026-11-22 15:30:00')
        ->and($this->leased->fresh()->monthly_start_km)->toEqual(10000.0)
        // Lệnh lấy đúng KM over trong xe, kể cả xe đang chạy chuyến
        ->and(VehicleKmCycle::firstWhere('vehicle_id', $this->running->id)->end_km)->toEqual(52800.0);
});

it('replaces the stored cycle with the final numbers when the 23rd snapshot runs', function (): void {
    $this->travelTo(Carbon::parse('2026-11-22 15:30:00'));
    $this->artisan('vehicles:record-km-cycle')->assertSuccessful();

    $this->leased->update(['current_mileage' => 13800]);
    $this->travelTo(Carbon::parse('2026-11-23 12:00:00'));
    $this->artisan('vehicles:snapshot-monthly-km')->assertSuccessful();

    expect(VehicleKmCycle::where('vehicle_id', $this->leased->id)->count())->toBe(1)
        ->and(VehicleKmCycle::firstWhere('vehicle_id', $this->leased->id))
        ->end_km->toEqual(13800.0)
        ->km_run->toEqual(3800.0)
        ->over_km->toEqual(800.0)
        ->cycle_end->toDateTimeString()->toBe('2026-11-23 12:00:00');
});
