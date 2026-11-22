<?php

use App\Enums\CheckpointType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Filament\Pages\VehicleKmReport;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Export\SimpleTableExcelExporter;
use App\Services\Km\VehicleKmReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function kmReading(Trip $trip, float $km, string $occurredAt, ?string $adjustedAt = null): TripCheckpoint
{
    return TripCheckpoint::create([
        'trip_id' => $trip->id,
        'checkpoint_type' => CheckpointType::ArrivedPickup->value,
        'km_reading' => $km,
        'occurred_at' => Carbon::parse($occurredAt),
        'adjusted_at' => $adjustedAt !== null ? Carbon::parse($adjustedAt) : null,
        'driver_id' => $trip->driver_id,
    ]);
}

beforeEach(function (): void {
    Gate::before(fn () => true);
    $this->actingAs(User::factory()->create());

    $this->vehicleA = Vehicle::factory()->create(['plate_number' => '29C-111.11']);
    $tripA = Trip::factory()->create(['vehicle_id' => $this->vehicleA->id, 'status' => TripStatus::Completed]);
    kmReading($tripA, 1000, '2026-11-10 08:00');
    kmReading($tripA, 1100, '2026-11-20 10:00');
    kmReading($tripA, 1180, '2026-11-23 09:00', adjustedAt: '2026-11-21 12:00');
    kmReading($tripA, 1250, '2026-11-25 18:00');

    $cancelledTrip = Trip::factory()->create(['vehicle_id' => $this->vehicleA->id, 'status' => TripStatus::Cancelled]);
    kmReading($cancelledTrip, 99999, '2026-11-21 15:00');

    $this->vehicleB = Vehicle::factory()->create(['plate_number' => '29C-222.22']);
    $tripB = Trip::factory()->create(['vehicle_id' => $this->vehicleB->id, 'status' => TripStatus::Completed]);
    kmReading($tripB, 500, '2026-11-16 07:00');
    kmReading($tripB, 620, '2026-11-18 19:30');

    $this->idleVehicle = Vehicle::factory()->create(['plate_number' => '29C-333.33']);
    $this->rentVehicle = Vehicle::factory()->create(['plate_number' => '29C-444.44', 'type' => VehicleOwnerType::Rent]);

    $this->from = Carbon::parse('2026-11-15 00:00');
    $this->to = Carbon::parse('2026-11-22 23:59:59');
});

it('takes the start km from the latest reading before the period and ignores cancelled trips', function (): void {
    $summary = app(VehicleKmReportService::class)
        ->summarize([$this->vehicleA->id, $this->vehicleB->id, $this->idleVehicle->id], $this->from, $this->to);

    expect($summary[$this->vehicleA->id])
        ->start_km->toBe(1000.0)
        ->start_in_range->toBeFalse()
        ->end_km->toBe(1180.0)
        ->km_run->toBe(180.0)
        ->trip_count->toBe(1)
        ->and($summary[$this->vehicleA->id]['end_at']->format('Y-m-d H:i'))->toBe('2026-11-21 12:00');

    expect($summary[$this->vehicleB->id])
        ->start_km->toBe(500.0)
        ->start_in_range->toBeTrue()
        ->end_km->toBe(620.0)
        ->km_run->toBe(120.0);

    expect($summary[$this->idleVehicle->id])
        ->start_km->toBeNull()
        ->end_km->toBeNull()
        ->km_run->toBeNull()
        ->trip_count->toBe(0);
});

it('shows company vehicles with km run for the chosen period', function (): void {
    Livewire::test(VehicleKmReport::class)
        ->loadTable()
        ->set('tableFilters.period.from', '2026-11-15 00:00')
        ->set('tableFilters.period.to', '2026-11-22 23:59')
        ->assertCanSeeTableRecords([$this->vehicleA, $this->vehicleB, $this->idleVehicle])
        ->assertCanNotSeeTableRecords([$this->rentVehicle])
        ->assertTableColumnStateSet('km_run', 180.0, $this->vehicleA)
        ->assertTableColumnStateSet('km_run', 120.0, $this->vehicleB)
        ->callTableAction('exportExcel')
        ->assertFileDownloaded();
});

it('exports the filtered vehicle list to excel', function (): void {
    Livewire::test(ListVehicles::class)
        ->loadTable()
        ->callAction('exportExcel')
        ->assertFileDownloaded();
});

it('writes title, headers and rows into the spreadsheet', function (): void {
    $sheet = app(SimpleTableExcelExporter::class)
        ->build('Tiêu đề', ['Biển số', 'Km'], [['29C-111.11', 1180.0], ['29C-222.22', null]])
        ->getActiveSheet();

    expect($sheet->getCell('A1')->getValue())->toBe('Tiêu đề')
        ->and($sheet->getCell('B2')->getValue())->toBe('Km')
        ->and($sheet->getCell('A3')->getValue())->toBe('29C-111.11')
        ->and((float) $sheet->getCell('B3')->getValue())->toBe(1180.0)
        ->and($sheet->getCell('A4')->getValue())->toBe('29C-222.22');
});
