<?php

namespace App\Console\Commands;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\LocationType;
use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\ShiftType;
use App\Enums\TripStatus;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\TripKmCalculatorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

class FullWorkflowTest extends Command
{
    protected $signature = 'workflow:full-test
                            {--base-url=http://localhost:8000 : API base URL}
                            {--seed : Run DB seeders before testing}';

    protected $description = '🚛 Full TMS workflow test — 7 scenarios with real API calls';

    private string $ts;

    private string $baseUrl;

    private Area $hhhkArea;

    private Area $externalArea;

    private Customer $customer;

    private Location $pickup;

    private Location $delivery;

    private Location $delivery2;

    private Vehicle $companyVehicle;

    private Vehicle $rentVehicle;

    private User $driverA;

    private User $driverB;

    private string $tokenA;

    private string $tokenB;

    // ─── Console helpers ─────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <bg=yellow;fg=black> '.str_pad(" {$title} ", 80, ' ', STR_PAD_RIGHT).' </>');
    }

    private function step(string $label): void
    {
        $this->line("  <fg=gray>▸</> {$label}");
    }

    private function ok(string $label, mixed $detail = null): void
    {
        $msg = "  <fg=green>✔</> {$label}";
        if ($detail !== null) {
            $msg .= " <fg=gray>→</> <fg=bright-white>{$detail}</>";
        }
        $this->line($msg);
    }

    private function err(string $label, mixed $detail = null): void
    {
        $msg = "  <fg=red>✘</> {$label}";
        if ($detail !== null) {
            $msg .= " <fg=gray>→</> <fg=red>{$detail}</>";
        }
        $this->line($msg);
    }

    private function cinfo(string $label, mixed $detail = null): void
    {
        $msg = "  <fg=blue>ℹ</> {$label}";
        if ($detail !== null) {
            $msg .= " <fg=gray>→</> {$detail}";
        }
        $this->line($msg);
    }

    // ─── API call helpers ────────────────────────────────────────────────

    private function api(string $token, string $method, string $path, array $data = []): array
    {
        $url = rtrim($this->baseUrl, '/').$path;

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->{$method}($url, $data);

            return [
                'status' => $response->status(),
                'json' => $response->json(),
                'ok' => $response->successful(),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 0,
                'json' => ['error' => $e->getMessage()],
                'ok' => false,
            ];
        }
    }

    private function assertOk(array $result, string $label): bool
    {
        if ($result['ok']) {
            $this->ok($label, "HTTP {$result['status']}");

            return true;
        }

        $msg = $result['json']['message'] ?? ($result['json']['error'] ?? json_encode($result['json']));
        $this->err($label, "HTTP {$result['status']} — {$msg}");

        return false;
    }

    // ─── Handle ──────────────────────────────────────────────────────────

    public function handle(): void
    {
        $this->baseUrl = $this->option('base-url');

        $this->newLine();
        $this->line('  <fg=bright-white;bg=blue>  🚛  TMS FULL WORKFLOW TEST  🚛  </>');
        $this->line("  <fg=gray>API: {$this->baseUrl}</>");
        $this->line('  <fg=gray>Time: '.now()->format('Y-m-d H:i:s').'</>');

        $this->step('Checking API server...');
        try {
            $ping = Http::timeout(2)
                ->get(rtrim($this->baseUrl, '/').'/api/driver/shifts/current');
        } catch (\Throwable $e) {
            $this->err('API server NOT reachable at '.$this->baseUrl);
            $this->newLine();
            $this->line('  <fg=yellow>💡 Start the dev server first:</>');
            $this->line('  <fg=green>   php artisan serve</>');
            $this->newLine();

            return;
        }
        $this->ok('API server reachable');

        $this->section('SETUP — Creating test data');

        if ($this->option('seed')) {
            $this->step('Running database seeders...');
            $this->call('db:seed', ['--no-interaction' => true]);
            $this->ok('Seeders completed');
        }

        $this->ts = (string) now()->timestamp;

        $this->setupData();
        $this->setupAuth();

        $this->section('AUTH — Creating Sanctum tokens');
        $this->ok('Driver A token', substr($this->tokenA, 0, 10).'...');
        $this->ok('Driver B token', substr($this->tokenB, 0, 10).'...');

        $scenarios = [
            'scenario1',
            'scenario2',
            'scenario3',
            'scenario4',
            'scenario5',
            'scenario6',
            'scenario7',
        ];

        $results = [];
        foreach ($scenarios as $i => $method) {
            $num = $i + 1;
            $results[$method] = $this->{$method}($num);
        }

        $this->section('SUMMARY');
        $passed = count(array_filter($results));
        $total = count($results);

        $this->table(
            ['#', 'Scenario', 'Result'],
            collect([
                [1, 'HHHK order A→B', $results['scenario1'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'],
                [2, 'External order', $results['scenario2'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'],
                [3, '2 orders same trip', $results['scenario3'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'],
                [4, 'Driver swap mid-trip', $results['scenario4'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'],
                [5, 'Return trip (empty)', $results['scenario5'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'],
                [6, 'Rented vehicle', $results['scenario6'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'],
                [7, 'Shift KM summary', $results['scenario7'] ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'],
            ])->toArray()
        );

        $this->newLine();
        if ($passed === $total) {
            $this->line("  <fg=green;bg=black>  ✅  ALL {$total}/{$total} SCENARIOS PASSED  </>");
        } else {
            $this->line("  <fg=yellow;bg=black>  ⚠️  {$passed}/{$total} PASSED, ".($total - $passed).' FAILED  </>');
        }
        $this->newLine();
    }

    // ─── Setup ───────────────────────────────────────────────────────────

    private function setupData(): void
    {
        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

        $this->hhhkArea = Area::firstOrCreate(
            ['type' => OrderType::Hhhk->value, 'code' => 'FW-AREA'],
            ['name' => 'Full Workflow Area']
        );
        $this->externalArea = Area::firstOrCreate(
            ['type' => OrderType::External->value, 'code' => 'FW-EXT'],
            ['name' => 'Full Workflow External Area']
        );
        $this->customer = Customer::firstOrCreate(
            ['code' => 'FW-CUST'],
            ['name' => 'Full Workflow Customer', 'is_active' => true]
        );
        $this->pickup = Location::firstOrCreate(
            ['code' => 'FW-PICKUP'],
            ['name' => 'Kho A (Pickup)', 'address' => '123 Pickup St, HCMC',
                'lat' => 10.8231, 'lng' => 106.6297, 'loc_type' => LocationType::Pickup, 'is_active' => true]
        );
        $this->delivery = Location::firstOrCreate(
            ['code' => 'FW-DELIV'],
            ['name' => 'Kho B (Delivery)', 'address' => '456 Delivery St, HCMC',
                'lat' => 10.8500, 'lng' => 106.6500, 'loc_type' => LocationType::Delivery, 'is_active' => true]
        );
        $this->delivery2 = Location::firstOrCreate(
            ['code' => 'FW-DELIV2'],
            ['name' => 'Kho C (Delivery 2)', 'address' => '789 Second Delivery, HCMC',
                'lat' => 10.8800, 'lng' => 106.6700, 'loc_type' => LocationType::Delivery, 'is_active' => true]
        );

        $this->companyVehicle = Vehicle::firstOrCreate(
            ['plate_number' => '51C-FW001'],
            [
                'vehicle_type' => VehicleType::Normal,
                'owner' => 'ASGT',
                'is_active' => true,
                'status' => VehicleStatus::On,
                'type' => VehicleOwnerType::Company,
                'current_mileage' => 50000,
                'load_capacity' => 15000,
            ]
        );
        $this->rentVehicle = Vehicle::firstOrCreate(
            ['plate_number' => '51C-FW002'],
            [
                'vehicle_type' => VehicleType::Normal,
                'owner' => 'RENTAL',
                'is_active' => true,
                'status' => VehicleStatus::On,
                'type' => VehicleOwnerType::Rent,
                'current_mileage' => 100000,
                'load_capacity' => 10000,
            ]
        );

        $driverRole = Role::where('name', 'driver')->first();
        $this->driverA = User::firstOrCreate(
            ['email' => 'fw-driver-a@tms.test'],
            ['name' => 'FW Driver A', 'password' => bcrypt('password')]
        );
        $this->driverA->assignRole($driverRole);

        $this->driverB = User::firstOrCreate(
            ['email' => 'fw-driver-b@tms.test'],
            ['name' => 'FW Driver B', 'password' => bcrypt('password')]
        );
        $this->driverB->assignRole($driverRole);

        $this->ok('Areas', "HHHK={$this->hhhkArea->code}, EXT={$this->externalArea->code}");
        $this->ok('Customer', $this->customer->code);
        $this->ok('Locations', "Pickup={$this->pickup->code}, Delivery={$this->delivery->code}, Delivery2={$this->delivery2->code}");
        $this->ok('Vehicles', "Company={$this->companyVehicle->plate_number}, Rent={$this->rentVehicle->plate_number}");
        $this->ok('Drivers', "A={$this->driverA->name}, B={$this->driverB->name}");
    }

    private function setupAuth(): void
    {
        $this->tokenA = $this->driverA->createToken('fw-test')->plainTextToken;
        $this->tokenB = $this->driverB->createToken('fw-test')->plainTextToken;
    }

    // ─── Scenario helpers ────────────────────────────────────────────────

    private function createOrderData(string $code, OrderType $type, Area $area, int $pickupId, int $deliveryId): Order
    {
        $order = Order::create([
            'order_code' => $code,
            'type' => $type,
            'area_id' => $area->id,
            'customer_id' => $this->customer->id,
            'cargo_name' => 'Test cargo '.$code,
            'cargo_type' => CargoType::Gcr,
            'total_packages' => 10,
            'total_weight' => 500,
            'pickup_location_id' => $pickupId,
            'pickup_address' => 'Test Pickup',
            'planned_loading_at' => now(),
            'status' => OrderStatus::Draft,
            'created_by' => $this->driverA->id,
        ]);

        OrderDeliveryPoint::create([
            'order_id' => $order->id,
            'location_id' => $deliveryId,
            'address' => 'Test Delivery',
            'sequence' => 1,
            'status' => OrderDeliveryPointStatus::Pending,
        ]);

        return $order;
    }

    private function assignAndSend(Order $order, Vehicle $vehicle, User $driver): Trip
    {
        $trip = Trip::create([
            'trip_code' => Trip::generateTripCode(),
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'status' => TripStatus::Pending,
            'start_location_id' => $order->pickup_location_id,
            'end_location_id' => $order->deliveryPoints()->orderBy('sequence', 'desc')->first()?->location_id,
        ]);

        $order->update(['trip_id' => $trip->id, 'status' => OrderStatus::Assigned]);
        $order->update(['status' => OrderStatus::Sent, 'sent_at' => now()]);

        return $trip;
    }

    private function startDriverShift(User $driver, Vehicle $vehicle, string $token): bool
    {
        $res = $this->api($token, 'post', '/api/driver/shifts/start', [
            'shift_type' => ShiftType::Full->value,
            'start_time' => now()->toIso8601String(),
            'vehicle_id' => $vehicle->id,
        ]);

        return $this->assertOk($res, 'Start shift');
    }

    private function sendCheckpoint(string $token, Trip $trip, string $type, ?int $km, ?int $orderId = null, ?int $dpId = null): bool
    {
        $data = [
            'checkpoint_type' => $type,
            'occurred_at' => now()->toIso8601String(),
        ];
        if ($km !== null) {
            $data['km_reading'] = $km;
        }
        if ($orderId !== null) {
            $data['order_id'] = $orderId;
        }
        if ($dpId !== null) {
            $data['delivery_point_id'] = $dpId;
        }

        $label = match ($type) {
            'started' => '▶ Started',
            'arrived_pickup' => '📦 Arrived Pickup',
            'left_pickup' => '🚚 Left Pickup',
            'arrived_delivery' => '📍 Arrived Delivery',
            'completed' => '✅ Completed',
            default => $type,
        };
        if ($km) {
            $label .= " (km={$km})";
        }

        $res = $this->api($token, 'post', "/api/driver/trips/{$trip->id}/checkpoints", $data);

        return $this->assertOk($res, $label);
    }

    private function endDriverShift(User $driver, int $endKm, string $token): bool
    {
        $res = $this->api($token, 'post', '/api/driver/shifts/end', [
            'end_km' => $endKm,
            'end_time' => now()->toIso8601String(),
        ]);

        return $this->assertOk($res, '🏁 End shift');
    }

    // ═════════════════════════════════════════════════════════════════════
    // SCENARIO 1: HHHK order A→B
    // ═════════════════════════════════════════════════════════════════════

    private function scenario1(int $num): bool
    {
        $this->section("SCENARIO {$num} — HHHK order A→B (full lifecycle)");
        $km = 50000;

        $this->cinfo('Vehicle mileage', $km);

        $this->step('Creating order...');
        $order = $this->createOrderData('FW-S1', OrderType::Hhhk, $this->hhhkArea, $this->pickup->id, $this->delivery->id);
        $dp = $order->deliveryPoints()->first();
        $this->ok('Order', "{$order->order_code} — Draft");

        $this->step('Assigning trip + sending order...');
        $trip = $this->assignAndSend($order, $this->companyVehicle, $this->driverA);
        $this->ok('Trip', "{$trip->trip_code} — Sent");

        $this->startDriverShift($this->driverA, $this->companyVehicle, $this->tokenA);
        $this->sendCheckpoint($this->tokenA, $trip, 'started', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_pickup', $km + 10);
        $this->sendCheckpoint($this->tokenA, $trip, 'left_pickup', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_delivery', null, $order->id, $dp->id);
        $this->sendCheckpoint($this->tokenA, $trip, 'completed', $km + 80, $order->id, $dp->id);
        $this->endDriverShift($this->driverA, $km + 80, $this->tokenA);

        $order->refresh();
        $trip->refresh();

        $this->cinfo('Order loaded_km', number_format((float) $order->loaded_km, 1).' km');
        $this->cinfo('Trip total_km', number_format((float) $trip->total_km, 1).' km');
        $this->cinfo('Trip loaded / empty', number_format((float) $trip->total_km_loaded, 1).' / '.number_format((float) $trip->total_km_empty, 1).' km');

        return $order->status === OrderStatus::Completed
            && $trip->status === TripStatus::Completed;
    }

    // ═════════════════════════════════════════════════════════════════════
    // SCENARIO 2: External order
    // ═════════════════════════════════════════════════════════════════════

    private function scenario2(int $num): bool
    {
        $this->section("SCENARIO {$num} — External order");
        $km = 60000;

        $order = $this->createOrderData('FW-S2', OrderType::External, $this->externalArea, $this->pickup->id, $this->delivery->id);
        $dp = $order->deliveryPoints()->first();
        $this->ok('Order', "{$order->order_code} — External type");

        $trip = $this->assignAndSend($order, $this->companyVehicle, $this->driverA);

        $this->startDriverShift($this->driverA, $this->companyVehicle, $this->tokenA);
        $this->sendCheckpoint($this->tokenA, $trip, 'started', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_pickup', $km + 15);
        $this->sendCheckpoint($this->tokenA, $trip, 'left_pickup', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_delivery', null, $order->id, $dp->id);
        $this->sendCheckpoint($this->tokenA, $trip, 'completed', $km + 55, $order->id, $dp->id);
        $this->endDriverShift($this->driverA, $km + 55, $this->tokenA);

        $order->refresh();
        $trip->refresh();

        $this->cinfo('Order loaded_km', number_format((float) $order->loaded_km, 1).' km');
        $this->cinfo('Trip status', $trip->status->getLabel());

        return $order->status === OrderStatus::Completed;
    }

    // ═════════════════════════════════════════════════════════════════════
    // SCENARIO 3: 2 orders same trip
    // ═════════════════════════════════════════════════════════════════════

    private function scenario3(int $num): bool
    {
        $this->section("SCENARIO {$num} — 2 HHHK orders, same trip, sequential delivery");
        $km = 70000;

        $order1 = $this->createOrderData('FW-S3A', OrderType::Hhhk, $this->hhhkArea, $this->pickup->id, $this->delivery->id);
        $order2 = $this->createOrderData('FW-S3B', OrderType::Hhhk, $this->hhhkArea, $this->pickup->id, $this->delivery2->id);
        $dp1 = $order1->deliveryPoints()->first();
        $dp2 = $order2->deliveryPoints()->first();

        $trip = Trip::create([
            'trip_code' => Trip::generateTripCode(),
            'vehicle_id' => $this->companyVehicle->id,
            'driver_id' => $this->driverA->id,
            'status' => TripStatus::Pending,
        ]);

        $order1->update(['trip_id' => $trip->id, 'status' => OrderStatus::Assigned, 'trip_sequence' => 0]);
        $order2->update(['trip_id' => $trip->id, 'status' => OrderStatus::Assigned, 'trip_sequence' => 1]);
        $order1->update(['status' => OrderStatus::Sent, 'sent_at' => now()]);
        $order2->update(['status' => OrderStatus::Sent, 'sent_at' => now()]);
        $this->ok('Orders', "{$order1->order_code} + {$order2->order_code} → Trip {$trip->trip_code}");

        $this->startDriverShift($this->driverA, $this->companyVehicle, $this->tokenA);
        $this->sendCheckpoint($this->tokenA, $trip, 'started', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_pickup', $km + 10);
        $this->sendCheckpoint($this->tokenA, $trip, 'left_pickup', null);

        // Deliver order 1
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_delivery', null, $order1->id, $dp1->id);
        $this->sendCheckpoint($this->tokenA, $trip, 'completed', $km + 60, $order1->id, $dp1->id);

        // Deliver order 2
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_delivery', null, $order2->id, $dp2->id);
        $this->sendCheckpoint($this->tokenA, $trip, 'completed', $km + 100, $order2->id, $dp2->id);

        $this->endDriverShift($this->driverA, $km + 100, $this->tokenA);

        $order1->refresh();
        $order2->refresh();
        $trip->refresh();

        $this->cinfo('Order 1 loaded_km', number_format((float) $order1->loaded_km, 1).' km');
        $this->cinfo('Order 2 loaded_km', number_format((float) $order2->loaded_km, 1).' km');
        $this->cinfo('Trip loaded (union)', number_format((float) $trip->total_km_loaded, 1).' km');
        $this->cinfo('Trip empty', number_format((float) $trip->total_km_empty, 1).' km');

        return $order1->status === OrderStatus::Completed
            && $order2->status === OrderStatus::Completed
            && $trip->status === TripStatus::Completed;
    }

    // ═════════════════════════════════════════════════════════════════════
    // SCENARIO 4: Driver swap
    // ═════════════════════════════════════════════════════════════════════

    private function scenario4(int $num): bool
    {
        $this->section("SCENARIO {$num} — Driver swap mid-trip");
        $km = 80000;

        $order = $this->createOrderData('FW-S4', OrderType::Hhhk, $this->hhhkArea, $this->pickup->id, $this->delivery->id);
        $dp = $order->deliveryPoints()->first();

        $trip = Trip::create([
            'trip_code' => Trip::generateTripCode(),
            'vehicle_id' => $this->companyVehicle->id,
            'driver_id' => $this->driverA->id,
            'status' => TripStatus::Pending,
        ]);
        $order->update(['trip_id' => $trip->id, 'status' => OrderStatus::Assigned]);
        $order->update(['status' => OrderStatus::Sent, 'sent_at' => now()]);
        $this->ok('Setup', "Order {$order->order_code} → Trip {$trip->trip_code}");

        // Driver A: start → pickup → left → end shift
        $this->startDriverShift($this->driverA, $this->companyVehicle, $this->tokenA);
        $this->sendCheckpoint($this->tokenA, $trip, 'started', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_pickup', $km + 10);
        $this->sendCheckpoint($this->tokenA, $trip, 'left_pickup', null);
        $this->endDriverShift($this->driverA, $km + 30, $this->tokenA);

        $trip->refresh();
        $order->refresh();
        $this->cinfo('After Driver A end shift', "Trip={$trip->status->getLabel()}, Order={$order->status->getLabel()}");

        // Reassign to Driver B
        $trip->update(['driver_id' => $this->driverB->id, 'status' => TripStatus::Started]);
        $this->ok('Reassigned', "Trip → Driver B ({$this->driverB->name})");

        // Driver B: start → complete
        $this->startDriverShift($this->driverB, $this->companyVehicle, $this->tokenB);
        $this->sendCheckpoint($this->tokenB, $trip, 'started', null);
        $this->sendCheckpoint($this->tokenB, $trip, 'arrived_delivery', null, $order->id, $dp->id);
        $this->sendCheckpoint($this->tokenB, $trip, 'completed', $km + 100, $order->id, $dp->id);

        // Recalculate trip KM
        app(TripKmCalculatorService::class)->calculate($trip);
        $this->endDriverShift($this->driverB, $km + 100, $this->tokenB);

        $order->refresh();
        $trip->refresh();

        $this->cinfo('Order loaded_km', number_format((float) $order->loaded_km, 1).' km');
        $this->cinfo('Trip total_km', number_format((float) $trip->total_km, 1).' km');

        return $order->status === OrderStatus::Completed
            && $trip->status === TripStatus::Completed;
    }

    // ═════════════════════════════════════════════════════════════════════
    // SCENARIO 5: Return trip (empty)
    // ═════════════════════════════════════════════════════════════════════

    private function scenario5(int $num): bool
    {
        $this->section("SCENARIO {$num} — Return trip (empty, quay đầu)");
        $km = 90000;

        $order = $this->createOrderData('FW-S5', OrderType::Hhhk, $this->hhhkArea, $this->pickup->id, $this->delivery->id);
        $dp = $order->deliveryPoints()->first();
        $trip = $this->assignAndSend($order, $this->companyVehicle, $this->driverA);

        $this->startDriverShift($this->driverA, $this->companyVehicle, $this->tokenA);
        $this->sendCheckpoint($this->tokenA, $trip, 'started', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_pickup', $km + 10);
        $this->sendCheckpoint($this->tokenA, $trip, 'left_pickup', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_delivery', null, $order->id, $dp->id);
        $this->sendCheckpoint($this->tokenA, $trip, 'completed', $km + 70, $order->id, $dp->id);

        $this->cinfo('Order delivered at KM', $km + 70);

        // Create return trip (empty)
        $this->step('Creating return trip (empty)...');
        $returnTrip = Trip::create([
            'trip_code' => Trip::generateTripCode(),
            'vehicle_id' => $this->companyVehicle->id,
            'driver_id' => $this->driverA->id,
            'status' => TripStatus::ReturnTrip,
            'start_location_id' => $this->delivery->id,
            'end_location_id' => $this->pickup->id,
            'started_at' => now(),
            'start_km' => $km + 70,
        ]);
        $this->ok('Return trip', $returnTrip->trip_code);

        // Simulate driving back empty and completing return trip
        TripCheckpoint::create([
            'trip_id' => $returnTrip->id,
            'checkpoint_type' => CheckpointType::Completed,
            'occurred_at' => now(),
            'km_reading' => $km + 100,
            'driver_id' => $this->driverA->id,
        ]);

        $returnTrip->complete(endKm: $km + 100);
        app(TripKmCalculatorService::class)->calculate($returnTrip);

        $this->endDriverShift($this->driverA, $km + 100, $this->tokenA);

        $order->refresh();
        $returnTrip->refresh();

        $this->cinfo('Main trip loaded_km', number_format((float) $order->loaded_km, 1).' km');
        $this->cinfo('Return trip total', number_format((float) $returnTrip->total_km, 1).' km');
        $this->cinfo('Return trip loaded', number_format((float) $returnTrip->total_km_loaded, 1).' km (empty)');
        $this->cinfo('Return trip empty', number_format((float) $returnTrip->total_km_empty, 1).' km');

        return $order->status === OrderStatus::Completed
            && $returnTrip->status === TripStatus::Completed
            && (float) $returnTrip->total_km_loaded === 0.0;
    }

    // ═════════════════════════════════════════════════════════════════════
    // SCENARIO 6: Rented vehicle
    // ═════════════════════════════════════════════════════════════════════

    private function scenario6(int $num): bool
    {
        $this->section("SCENARIO {$num} — Rented vehicle (xe thuê ngoài)");
        $km = 100000;

        $this->cinfo('Vehicle', "{$this->rentVehicle->plate_number} — ".$this->rentVehicle->type->getLabel());

        $order = $this->createOrderData('FW-S6', OrderType::Hhhk, $this->hhhkArea, $this->pickup->id, $this->delivery->id);
        $dp = $order->deliveryPoints()->first();
        $trip = $this->assignAndSend($order, $this->rentVehicle, $this->driverA);

        $this->ok('Trip with rent vehicle', $trip->trip_code);

        // Note: For rent vehicles, createCheckpointsForExternalVehicle would
        // auto-create checkpoints when called via Filament actions.
        // In CLI mode, we simulate the same checkpoints via API.

        $this->startDriverShift($this->driverA, $this->rentVehicle, $this->tokenA);
        $this->sendCheckpoint($this->tokenA, $trip, 'started', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_pickup', $km + 10);
        $this->sendCheckpoint($this->tokenA, $trip, 'left_pickup', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_delivery', null, $order->id, $dp->id);
        $this->sendCheckpoint($this->tokenA, $trip, 'completed', $km + 80, $order->id, $dp->id);
        $this->endDriverShift($this->driverA, $km + 80, $this->tokenA);

        $order->refresh();
        $trip->refresh();

        $this->cinfo('Order loaded_km', number_format((float) $order->loaded_km, 1).' km');
        $this->cinfo('Vehicle type', $trip->vehicle->type->getLabel());

        return $order->status === OrderStatus::Completed
            && $trip->vehicle->type === VehicleOwnerType::Rent;
    }

    // ═════════════════════════════════════════════════════════════════════
    // SCENARIO 7: Shift KM summary
    // ═════════════════════════════════════════════════════════════════════

    private function scenario7(int $num): bool
    {
        $this->section("SCENARIO {$num} — Shift KM summary");
        $km = 110000;

        $order = $this->createOrderData('FW-S7', OrderType::Hhhk, $this->hhhkArea, $this->pickup->id, $this->delivery->id);
        $dp = $order->deliveryPoints()->first();
        $trip = $this->assignAndSend($order, $this->companyVehicle, $this->driverA);

        $this->startDriverShift($this->driverA, $this->companyVehicle, $this->tokenA);

        $shift = DriverShift::where('driver_id', $this->driverA->id)
            ->whereNull('end_time')
            ->first();
        $this->cinfo('Shift started', "KM start = {$shift->start_km}");

        $this->sendCheckpoint($this->tokenA, $trip, 'started', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_pickup', $km + 20);
        $this->sendCheckpoint($this->tokenA, $trip, 'left_pickup', null);
        $this->sendCheckpoint($this->tokenA, $trip, 'arrived_delivery', null, $order->id, $dp->id);
        $this->sendCheckpoint($this->tokenA, $trip, 'completed', $km + 90, $order->id, $dp->id);
        $this->endDriverShift($this->driverA, $km + 90, $this->tokenA);

        $shift->refresh();
        $order->refresh();
        $trip->refresh();

        $this->newLine();
        $this->line('  <fg=bright-white>📊 SHIFT KM SUMMARY:</>');
        $this->line('  ┌──────────────────────────────────────┐');
        $this->line(sprintf('  │  Start KM:  %8s km              │', number_format((float) $shift->start_km, 1)));
        $this->line(sprintf('  │  End KM:    %8s km              │', number_format((float) $shift->end_km, 1)));
        $this->line(sprintf('  │  Total:     %8s km              │', number_format((float) $shift->total_km, 1)));
        $this->line(sprintf('  │  Loaded:    %8s km  (có hàng)   │', number_format((float) $shift->total_km_loaded, 1)));
        $this->line(sprintf('  │  Empty:     %8s km  (không hàng)│', number_format((float) $shift->total_km_empty, 1)));
        $this->line('  └──────────────────────────────────────┘');

        $this->cinfo('Order loaded_km', number_format((float) $order->loaded_km, 1).' km');

        return $order->status === OrderStatus::Completed
            && $shift->total_km !== null
            && (float) $shift->total_km > 0;
    }
}
