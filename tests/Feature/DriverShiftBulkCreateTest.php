<?php

use App\Enums\ShiftType;
use App\Filament\Resources\DriverShifts\Pages\ListDriverShifts;
use App\Models\DriverShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('can bulk create driver shifts via list page action', function () {
    Gate::before(fn () => true);

    $driverRole = Role::create(['name' => 'driver', 'guard_name' => 'web']);

    $admin = User::factory()->create();

    $driver1 = User::factory()->create(['name' => 'Driver One']);
    $driver1->assignRole($driverRole);

    $driver2 = User::factory()->create(['name' => 'Driver Two']);
    $driver2->assignRole($driverRole);

    $nonDriver = User::factory()->create(['name' => 'Non Driver']);

    $this->actingAs($admin);

    Livewire::test(ListDriverShifts::class)
        ->mountAction('bulkCreateShifts')
        ->setActionData([
            'driver_ids' => [$driver1->id, $driver2->id],
            'shift_type' => ShiftType::Full->value,
            'start_time' => now()->toIso8601String(),
            'end_time' => now()->addHours(8)->toIso8601String(),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(DriverShift::where('driver_id', $driver1->id)->count())->toBe(1);
    expect(DriverShift::where('driver_id', $driver2->id)->count())->toBe(1);
    expect(DriverShift::where('driver_id', $nonDriver->id)->count())->toBe(0);

    $shift1 = DriverShift::where('driver_id', $driver1->id)->first();
    expect($shift1->shift_type)->toBe(ShiftType::Full);
    expect($shift1->start_time)->not->toBeNull();
});
