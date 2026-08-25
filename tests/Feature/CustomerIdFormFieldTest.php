<?php

use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->area1 = Area::create([
        'type' => 'HHHK',
        'code' => 'AREA-01',
        'name' => 'Khu vực 1',
        'is_active' => true,
    ]);

    $this->area2 = Area::create([
        'type' => 'external',
        'code' => 'AREA-02',
        'name' => 'Khu vực 2',
        'is_active' => true,
    ]);

    $this->loc1 = Location::create([
        'code' => 'LOC-01',
        'name' => 'Địa điểm 1',
        'area_id' => $this->area1->id,
        'is_active' => true,
    ]);

    $this->loc2 = Location::create([
        'code' => 'LOC-02',
        'name' => 'Địa điểm 2',
        'area_id' => $this->area2->id,
        'is_active' => true,
    ]);

    $this->customer = Customer::create([
        'code' => 'CUST-01',
        'name' => 'Khách hàng 01',
        'is_active' => true,
    ]);

    // Link customer to both locations via pivot
    DB::table('customer_location')->insert([
        [
            'customer_id' => $this->customer->id,
            'location_id' => $this->loc1->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'customer_id' => $this->customer->id,
            'location_id' => $this->loc2->id,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $this->runAfterStateUpdated = function (Select $field, mixed $state, Set $set, Get $get) {
        $reflection = new ReflectionProperty($field, 'afterStateUpdated');
        $reflection->setAccessible(true);
        $callbacks = $reflection->getValue($field);
        foreach ($callbacks as $callback) {
            $callback($state, $set, $get);
        }
    };
});

test('it sets matching pickup_location_id based on current area_id', function () {
    $field = CreatesOrderTransportCards::getCustomerIdFormField(false);
    expect($field)->toBeInstanceOf(Select::class);

    $stateValues = [];
    $mockSet = new class($stateValues) extends Set
    {
        public function __construct(private array &$stateValues) {}

        public function __invoke(string|Component $path, mixed $state, bool $isAbsolute = false, bool $shouldCallUpdatedHooks = false): mixed
        {
            $this->stateValues[(string) $path] = $state;

            return null;
        }
    };

    $mockGet = new class($this->area2->id) extends Get
    {
        public function __construct(private int $areaId) {}

        public function __invoke(string|Component|null $path = null, bool $isAbsolute = false): mixed
        {
            if ($path === 'area_id') {
                return $this->areaId;
            }

            return null;
        }
    };

    ($this->runAfterStateUpdated)($field, $this->customer->id, $mockSet, $mockGet);

    expect($stateValues['pickup_location_id'])->toBe($this->loc2->id);
});

test('it sets both pickup_location_id and area_id when setAreaId is true', function () {
    $field = CreatesOrderTransportCards::getCustomerIdFormField(true);

    $stateValues = [];
    $mockSet = new class($stateValues) extends Set
    {
        public function __construct(private array &$stateValues) {}

        public function __invoke(string|Component $path, mixed $state, bool $isAbsolute = false, bool $shouldCallUpdatedHooks = false): mixed
        {
            $this->stateValues[(string) $path] = $state;

            return null;
        }
    };

    $mockGet = new class extends Get
    {
        public function __construct() {}

        public function __invoke(string|Component|null $path = null, bool $isAbsolute = false): mixed
        {
            return null;
        }
    };

    ($this->runAfterStateUpdated)($field, $this->customer->id, $mockSet, $mockGet);

    expect($stateValues['pickup_location_id'])->toBe($this->loc1->id);
    expect($stateValues['area_id'])->toBe($this->area1->id);
});

test('it resets pickup_location_id when customer state is blank', function () {
    $field = CreatesOrderTransportCards::getCustomerIdFormField(false);

    $stateValues = ['pickup_location_id' => 999];
    $mockSet = new class($stateValues) extends Set
    {
        public function __construct(private array &$stateValues) {}

        public function __invoke(string|Component $path, mixed $state, bool $isAbsolute = false, bool $shouldCallUpdatedHooks = false): mixed
        {
            $this->stateValues[(string) $path] = $state;

            return null;
        }
    };

    $mockGet = new class extends Get
    {
        public function __construct() {}

        public function __invoke(string|Component|null $path = null, bool $isAbsolute = false): mixed
        {
            return null;
        }
    };

    ($this->runAfterStateUpdated)($field, null, $mockSet, $mockGet);

    expect($stateValues['pickup_location_id'])->toBeNull();
});

test('it falls back to customer direct location_id when pivot has no records', function () {
    $custDirect = Customer::create([
        'code' => 'CUST-DIRECT',
        'name' => 'Khách hàng có direct location',
        'location_id' => $this->loc2->id,
        'is_active' => true,
    ]);

    $field = CreatesOrderTransportCards::getCustomerIdFormField(false);

    $stateValues = [];
    $mockSet = new class($stateValues) extends Set
    {
        public function __construct(private array &$stateValues) {}

        public function __invoke(string|Component $path, mixed $state, bool $isAbsolute = false, bool $shouldCallUpdatedHooks = false): mixed
        {
            $this->stateValues[(string) $path] = $state;

            return null;
        }
    };

    $mockGet = new class extends Get
    {
        public function __construct() {}

        public function __invoke(string|Component|null $path = null, bool $isAbsolute = false): mixed
        {
            return null;
        }
    };

    ($this->runAfterStateUpdated)($field, $custDirect->id, $mockSet, $mockGet);

    expect($stateValues['pickup_location_id'])->toBe($this->loc2->id);
});
