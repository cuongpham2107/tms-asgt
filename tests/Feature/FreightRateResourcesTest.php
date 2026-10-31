<?php

use App\Enums\CargoType;
use App\Filament\Resources\FeeTypes\Pages\ListFeeTypes;
use App\Filament\Resources\FreightRates\Pages\ListFreightRates;
use App\Filament\Resources\WaitingFeeRates\Pages\ListWaitingFeeRates;
use App\Models\Customer;
use App\Models\FeeType;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\User;
use App\Models\WaitingFeeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Gate::before(fn () => true);
    $this->actingAs(User::factory()->create());

    $this->customer = Customer::create(['code' => 'SAFO', 'name' => 'SAFO', 'is_active' => true]);
    $this->origin = Location::create(['code' => 'FUKANG', 'name' => 'Fukang', 'loc_type' => 'pickup', 'is_active' => true]);
    $this->destination = Location::create(['code' => 'ALSC', 'name' => 'Nhà ga ALSC', 'loc_type' => 'delivery', 'is_active' => true]);
});

test('freight rate list shows rates and creates a route with prices per tonnage', function () {
    $rate = FreightRate::create([
        'customer_id' => $this->customer->id,
        'origin_location_id' => $this->origin->id,
        'destination_location_id' => $this->destination->id,
        'cargo_type' => CargoType::Gcr,
        'prices' => ['5' => 1_300_000],
    ]);

    Livewire::test(ListFreightRates::class)
        ->assertCanSeeTableRecords([$rate])
        ->assertSee('1.300.000')
        ->callAction('create', data: [
            'customer_id' => $this->customer->id,
            'origin_location_id' => $this->origin->id,
            'destination_location_id' => $this->destination->id,
            'cargo_type' => CargoType::Dangerous->value,
            'prices' => ['2,5' => '800.000', '8' => '2200000'],
        ])
        ->assertHasNoActionErrors();

    $created = FreightRate::where('cargo_type', 'DGR')->firstOrFail();

    expect($created->priceFor(2.5))->toBe(800_000.0)
        ->and($created->priceFor(8))->toBe(2_200_000.0)
        ->and($created->priceFor(5))->toBeNull();
});

test('waiting fee and fee type lists render their records', function () {
    $waitingFee = WaitingFeeRate::create(['customer_id' => $this->customer->id, 'tiers' => [['tonnage' => 5, 'fee' => 50_000, 'free_minutes' => 120]]]);

    Livewire::test(ListWaitingFeeRates::class)
        ->assertCanSeeTableRecords([$waitingFee])
        ->callAction('create', data: [
            'customer_id' => $this->customer->id,
            'waiting_mode' => WaitingFeeRate::MODE_NORMAL,
            'fee_block_minutes' => 60,
            'grace_minutes' => 19,
            'tiers' => [['tonnage' => 8, 'fee' => 70_000, 'free_minutes' => 120]],
        ])
        ->assertHasNoActionErrors();

    expect(WaitingFeeRate::latest('id')->first()->tierFor(8))->toEqual(['tonnage' => 8, 'fee' => 70_000, 'free_minutes' => 120]);
    Livewire::test(ListFeeTypes::class)->assertCanSeeTableRecords(FeeType::orderBy('sort')->limit(10)->get());
});
