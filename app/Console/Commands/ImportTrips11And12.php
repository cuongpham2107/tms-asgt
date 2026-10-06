<?php

namespace App\Console\Commands;

use App\Models\DriverShift;
use App\Models\Order;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\TripDriverAssignment;
use App\Models\TripLeg;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleGpsPoint;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class ImportTrips11And12 extends Command
{
    protected $signature = 'trips:import-11-12 {--file= : Đường dẫn file JSON export}';

    protected $description = 'Import dữ liệu chuyến 11 và 12 (kèm đơn hàng, checkpoints, lượt lái, chặng, và toạ độ GPS) lên hệ thống';

    public function handle(): int
    {
        $filePath = $this->option('file') ?: database_path('exports/trips_11_12_export.json');

        if (! file_exists($filePath)) {
            $this->error("Không tìm thấy file: {$filePath}");

            return 1;
        }

        $this->info("Đang đọc dữ liệu từ: {$filePath}...");
        $json = file_get_contents($filePath);
        $data = json_decode($json, true);

        if (! $data) {
            $this->error('File JSON không hợp lệ.');

            return 1;
        }

        Role::firstOrCreate(['name' => 'driver', 'guard_name' => 'web']);

        DB::beginTransaction();

        try {
            // 1. Map / tạo tài xế
            $driverIdMap = [];
            foreach ($data['drivers'] as $d) {
                $driver = User::where('email', $d['email'])
                    ->orWhere(fn ($q) => ! empty($d['phone']) ? $q->where('phone', $d['phone']) : null)
                    ->orWhere('name', $d['name'])
                    ->first();

                if (! $driver) {
                    $driver = new User;
                    $driver->name = $d['name'];
                    $driver->email = $d['email'];
                    $driver->phone = $d['phone'] ?? null;
                    $driver->password = $d['password'] ?? bcrypt('12345678');
                    $driver->is_active = true;
                    $driver->station = $d['station'] ?? null;
                    $driver->save();
                    $driver->assignRole('driver');
                    $this->line("  + Đã tạo tài xế: {$driver->name}");
                }

                $driverIdMap[$d['id']] = $driver->id;
            }

            // 2. Map / tạo phương tiện
            $vehicleIdMap = [];
            foreach ($data['vehicles'] as $v) {
                $vehicle = Vehicle::where('plate_number', $v['plate_number'])->first();

                if (! $vehicle) {
                    $vehicleData = $v;
                    unset($vehicleData['id']);
                    $vehicle = Vehicle::create($vehicleData);
                    $this->line("  + Đã tạo xe: {$vehicle->plate_number}");
                }

                $vehicleIdMap[$v['id']] = $vehicle->id;
            }

            // 3. Map / tạo ca làm việc
            $shiftIdMap = [];
            foreach ($data['shifts'] as $s) {
                $newDriverId = $driverIdMap[$s['driver_id']] ?? $s['driver_id'];
                $shift = DriverShift::where('driver_id', $newDriverId)
                    ->where('start_time', $s['start_time'])
                    ->first();

                if (! $shift) {
                    $shift = new DriverShift;
                    $shift->driver_id = $newDriverId;
                    $shift->shift_type = $s['shift_type'];
                    $shift->start_time = $s['start_time'];
                    $shift->end_time = $s['end_time'];
                    $shift->total_km = $s['total_km'];
                    $shift->total_km_loaded = $s['total_km_loaded'];
                    $shift->total_km_empty = $s['total_km_empty'];
                    $shift->save();
                }

                $shiftIdMap[$s['id']] = $shift->id;
            }

            // 4. Map / tạo đơn hàng & điểm giao
            $orderIdMap = [];
            $dpIdMap = [];

            foreach ($data['orders'] as $ord) {
                $order = Order::where('order_code', $ord['order_code'])->first();
                if (! $order) {
                    $order = new Order;
                }

                $orderData = $ord;
                unset($orderData['id'], $orderData['trip_id']);
                $order->fill($orderData);
                $order->save();

                $orderIdMap[$ord['id']] = $order->id;
                $this->line("  + Đơn hàng: {$order->order_code} (ID: {$order->id})");
            }

            foreach ($data['delivery_points'] as $dp) {
                $newOrderId = $orderIdMap[$dp['order_id']] ?? $dp['order_id'];
                $deliveryPoint = OrderDeliveryPoint::where('order_id', $newOrderId)
                    ->where('sequence', $dp['sequence'])
                    ->first();

                if (! $deliveryPoint) {
                    $deliveryPoint = new OrderDeliveryPoint;
                }

                $dpData = $dp;
                unset($dpData['id']);
                $dpData['order_id'] = $newOrderId;
                $deliveryPoint->fill($dpData);
                $deliveryPoint->save();

                $dpIdMap[$dp['id']] = $deliveryPoint->id;
            }

            // 5. Map / tạo Chuyến xe
            $tripIdMap = [];
            foreach ($data['trips'] as $tr) {
                $trip = Trip::where('trip_code', $tr['trip_code'])->first();
                if (! $trip) {
                    $trip = new Trip;
                }

                $tripData = $tr;
                unset($tripData['id']);
                $tripData['vehicle_id'] = $vehicleIdMap[$tr['vehicle_id']] ?? $tr['vehicle_id'];
                $tripData['driver_id'] = $driverIdMap[$tr['driver_id']] ?? $tr['driver_id'];

                $trip->fill($tripData);
                $trip->save();

                $tripIdMap[$tr['id']] = $trip->id;
                $this->info("  ✓ Đã import chuyến: {$trip->trip_code} (ID mới: {$trip->id})");

                // Gán trip_id cho đơn hàng tương ứng
                foreach ($data['orders'] as $ord) {
                    if ($ord['trip_id'] == $tr['id']) {
                        $newOrdId = $orderIdMap[$ord['id']] ?? null;
                        if ($newOrdId) {
                            Order::where('id', $newOrdId)->update(['trip_id' => $trip->id]);
                        }
                    }
                }
            }

            // 6. Xoá & nạp lại lượt lái (assignments)
            foreach ($data['assignments'] as $asgn) {
                $newTripId = $tripIdMap[$asgn['trip_id']] ?? $asgn['trip_id'];
                $newDriverId = $driverIdMap[$asgn['driver_id']] ?? $asgn['driver_id'];
                $newShiftId = $shiftIdMap[$asgn['shift_id']] ?? $asgn['shift_id'];

                TripDriverAssignment::updateOrCreate(
                    [
                        'trip_id' => $newTripId,
                        'driver_id' => $newDriverId,
                        'started_at' => $asgn['started_at'],
                    ],
                    [
                        'shift_id' => $newShiftId,
                        'ended_at' => $asgn['ended_at'],
                        'km' => $asgn['km'],
                        'km_loaded' => $asgn['km_loaded'],
                        'km_empty' => $asgn['km_empty'],
                    ]
                );
            }

            // 7. Xoá & nạp lại Checkpoints
            foreach ($data['checkpoints'] as $cp) {
                $newTripId = $tripIdMap[$cp['trip_id']] ?? $cp['trip_id'];
                $newOrderId = ! empty($cp['order_id']) ? ($orderIdMap[$cp['order_id']] ?? $cp['order_id']) : null;
                $newDpId = ! empty($cp['delivery_point_id']) ? ($dpIdMap[$cp['delivery_point_id']] ?? $cp['delivery_point_id']) : null;
                $newDriverId = ! empty($cp['driver_id']) ? ($driverIdMap[$cp['driver_id']] ?? $cp['driver_id']) : null;
                $newShiftId = ! empty($cp['shift_id']) ? ($shiftIdMap[$cp['shift_id']] ?? $cp['shift_id']) : null;

                TripCheckpoint::updateOrCreate(
                    [
                        'trip_id' => $newTripId,
                        'checkpoint_type' => $cp['checkpoint_type'],
                        'occurred_at' => $cp['occurred_at'],
                    ],
                    [
                        'order_id' => $newOrderId,
                        'delivery_point_id' => $newDpId,
                        'driver_id' => $newDriverId,
                        'shift_id' => $newShiftId,
                        'gps_lat' => $cp['gps_lat'],
                        'gps_lng' => $cp['gps_lng'],
                        'voice_note' => $cp['voice_note'] ?? null,
                    ]
                );
            }

            // 8. Xoá & nạp lại Legs
            foreach ($data['legs'] as $leg) {
                $newTripId = $tripIdMap[$leg['trip_id']] ?? $leg['trip_id'];
                $newDriverId = ! empty($leg['driver_id']) ? ($driverIdMap[$leg['driver_id']] ?? $leg['driver_id']) : null;

                TripLeg::updateOrCreate(
                    [
                        'trip_id' => $newTripId,
                        'leg_index' => $leg['leg_index'],
                    ],
                    [
                        'driver_id' => $newDriverId,
                        'from_name' => $leg['from_name'],
                        'to_name' => $leg['to_name'],
                        'from_time' => $leg['from_time'],
                        'to_time' => $leg['to_time'],
                        'distance_km' => $leg['distance_km'],
                        'distance_adjusted_km' => $leg['distance_adjusted_km'],
                        'is_loaded' => $leg['is_loaded'],
                        'source' => $leg['source'] ?? 'gps',
                        'adjust_reason' => $leg['adjust_reason'],
                    ]
                );
            }

            // 9. Nạp toạ độ GPS (VehicleGpsPoint)
            if (! empty($data['gps_points'])) {
                $vId = $vehicleIdMap[$data['vehicles'][0]['id']] ?? $data['vehicles'][0]['id'];
                $minTime = Carbon::parse(collect($data['gps_points'])->min('recorded_at'))->format('Y-m-d H:i:s');
                $maxTime = Carbon::parse(collect($data['gps_points'])->max('recorded_at'))->format('Y-m-d H:i:s');

                // Xoá điểm trùng thời gian để tránh nhân bản
                VehicleGpsPoint::where('vehicle_id', $vId)
                    ->whereBetween('recorded_at', [$minTime, $maxTime])
                    ->delete();

                $gpsChunks = array_chunk($data['gps_points'], 200);
                $insertedGps = 0;

                foreach ($gpsChunks as $chunk) {
                    $insertRows = [];
                    foreach ($chunk as $pt) {
                        $insertRows[] = [
                            'vehicle_id' => $vId,
                            'recorded_at' => Carbon::parse($pt['recorded_at'])->format('Y-m-d H:i:s'),
                            'lat' => $pt['lat'],
                            'lng' => $pt['lng'],
                            'speed' => $pt['speed'] ?? null,
                            'heading' => $pt['heading'] ?? null,
                            'accuracy' => $pt['accuracy'] ?? null,
                            'source' => $pt['source'] ?? 'phone_gps',
                            'driver_id' => ! empty($pt['driver_id']) ? ($driverIdMap[$pt['driver_id']] ?? $pt['driver_id']) : null,
                            'shift_id' => ! empty($pt['shift_id']) ? ($shiftIdMap[$pt['shift_id']] ?? $pt['shift_id']) : null,
                            'device_id' => $pt['device_id'] ?? null,
                            'seq' => $pt['seq'] ?? null,
                            'mocked' => ! empty($pt['mocked']) ? 1 : 0,
                            'created_at' => ! empty($pt['created_at']) ? Carbon::parse($pt['created_at'])->format('Y-m-d H:i:s') : now()->format('Y-m-d H:i:s'),
                        ];
                    }
                    DB::table('vehicle_gps_points')->insertOrIgnore($insertRows);
                    $insertedGps += count($insertRows);
                }

                $this->info("  ✓ Đã import {$insertedGps} toạ độ GPS cho xe {$data['vehicles'][0]['plate_number']}");
            }

            DB::commit();

            $this->info("\n🎉 Hoàn thành import Chuyến 11 và Chuyến 12 thành công!");

            return 0;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Lỗi khi import: '.$e->getMessage());
            $this->error($e->getTraceAsString());

            return 1;
        }
    }
}
