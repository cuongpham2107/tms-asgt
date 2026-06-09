<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VehicleImportSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $vehicles = [
            ['make' => 'HINO', 'plate' => '20C04640', 'total_weight' => 10.4, 'load_capacity' => 4.115, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C05152', 'total_weight' => 10.4, 'load_capacity' => 5.76, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C08846', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C04581', 'total_weight' => 10.4, 'load_capacity' => 5.055, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '29E12490', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '20C04368', 'total_weight' => 10.4, 'load_capacity' => 5.105, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C06759', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '99H00948', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29H72338', 'total_weight' => 10.935, 'load_capacity' => 5.65, 'cargo_volume' => 41.48, 'box_length' => 6780, 'box_width' => 2390, 'box_height' => 2560, 'door_count' => 3, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H00988', 'total_weight' => 10.935, 'load_capacity' => 5.65, 'cargo_volume' => 41.48, 'box_length' => 6780, 'box_width' => 2390, 'box_height' => 2560, 'door_count' => 3, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H00973', 'total_weight' => 10.935, 'load_capacity' => 5.65, 'cargo_volume' => 41.48, 'box_length' => 6780, 'box_width' => 2390, 'box_height' => 2560, 'door_count' => 3, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29E12091', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29E12067', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29E08433', 'total_weight' => 10.96, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 5, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29E12041', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '20C04240', 'total_weight' => 10.4, 'load_capacity' => 5.025, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C04357', 'total_weight' => 10.4, 'load_capacity' => 5.015, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C04607', 'total_weight' => 10.4, 'load_capacity' => 5.005, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C04937', 'total_weight' => 10.4, 'load_capacity' => 5.045, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '99H00933', 'total_weight' => 10.93, 'load_capacity' => 5.65, 'cargo_volume' => 41.48, 'box_length' => 6780, 'box_width' => 2390, 'box_height' => 2560, 'door_count' => 3, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '20C04820', 'total_weight' => 10.4, 'load_capacity' => 5.035, 'cargo_volume' => 42.79, 'box_length' => 6800, 'box_width' => 2420, 'box_height' => 2600, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C09032', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C13806', 'total_weight' => 10.35, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '29E08245', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '20C08775', 'total_weight' => 10.4, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '29E09564', 'total_weight' => 10.4, 'load_capacity' => 4.75, 'cargo_volume' => 40.18, 'box_length' => 6765, 'box_width' => 2400, 'box_height' => 2475, 'door_count' => 3, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '20C13642', 'total_weight' => 10.35, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '29E09695', 'total_weight' => 10.35, 'load_capacity' => 5.25, 'cargo_volume' => 40.34, 'box_length' => 6570, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'ISUZU', 'plate' => '20C04427', 'total_weight' => 14, 'load_capacity' => 5.5, 'cargo_volume' => 57.72, 'box_length' => 7600, 'box_width' => 2450, 'box_height' => 3100, 'door_count' => 1, 'owner' => 'ASGL', 'type' => 'rent'],
            ['make' => 'ISUZU', 'plate' => '20C04429', 'total_weight' => 14, 'load_capacity' => 5.5, 'cargo_volume' => 57.72, 'box_length' => 7600, 'box_width' => 2450, 'box_height' => 3100, 'door_count' => 1, 'owner' => 'ASGL', 'type' => 'rent'],
            ['make' => 'ISUZU', 'plate' => '20C05328', 'total_weight' => 14, 'load_capacity' => 5.305, 'cargo_volume' => 57.62, 'box_length' => 7600, 'box_width' => 2438, 'box_height' => 3110, 'door_count' => 1, 'owner' => 'ASGL', 'type' => 'rent'],
            ['make' => 'ISUZU', 'plate' => '20C05338', 'total_weight' => 14, 'load_capacity' => 5.305, 'cargo_volume' => 57.62, 'box_length' => 7600, 'box_width' => 2438, 'box_height' => 3110, 'door_count' => 1, 'owner' => 'ASGL', 'type' => 'rent'],
            ['make' => 'ISUZU', 'plate' => '20C05656', 'total_weight' => 14, 'load_capacity' => 5.305, 'cargo_volume' => 57.62, 'box_length' => 7600, 'box_width' => 2438, 'box_height' => 3110, 'door_count' => 1, 'owner' => 'ASGL', 'type' => 'rent'],
            ['make' => 'ISUZU', 'plate' => '20C05592', 'total_weight' => 14, 'load_capacity' => 5.305, 'cargo_volume' => 57.62, 'box_length' => 7600, 'box_width' => 2438, 'box_height' => 3110, 'door_count' => 1, 'owner' => 'ASGL', 'type' => 'rent'],
            ['make' => 'HYUNDAI', 'plate' => '29E12452', 'total_weight' => 16.435, 'load_capacity' => 6.8, 'cargo_volume' => 43.33, 'box_length' => 7200, 'box_width' => 2360, 'box_height' => 2550, 'door_count' => 5, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HYUNDAI', 'plate' => '99H00968', 'total_weight' => 16.435, 'load_capacity' => 6.8, 'cargo_volume' => 43.33, 'box_length' => 7200, 'box_width' => 2360, 'box_height' => 2550, 'door_count' => 5, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HYUNDAI', 'plate' => '99H00944', 'total_weight' => 16.435, 'load_capacity' => 6.65, 'cargo_volume' => 44.11, 'box_length' => 7330, 'box_width' => 2360, 'box_height' => 2550, 'door_count' => 5, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HYUNDAI', 'plate' => '29C20161', 'total_weight' => 16.435, 'load_capacity' => 6.65, 'cargo_volume' => 44.11, 'box_length' => 7330, 'box_width' => 2360, 'box_height' => 2550, 'door_count' => 5, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29H81641', 'total_weight' => 15.14, 'load_capacity' => 6.7, 'cargo_volume' => 61.54, 'box_length' => 9980, 'box_width' => 2390, 'box_height' => 2580, 'door_count' => 4, 'owner' => 'CLS', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H01552', 'total_weight' => 15.14, 'load_capacity' => 6.7, 'cargo_volume' => 61.54, 'box_length' => 9980, 'box_width' => 2390, 'box_height' => 2580, 'door_count' => 4, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H01503', 'total_weight' => 15.14, 'load_capacity' => 6.7, 'cargo_volume' => 61.54, 'box_length' => 9980, 'box_width' => 2390, 'box_height' => 2580, 'door_count' => 4, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H01427', 'total_weight' => 15.14, 'load_capacity' => 6.7, 'cargo_volume' => 61.54, 'box_length' => 9980, 'box_width' => 2390, 'box_height' => 2580, 'door_count' => 4, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H01464', 'total_weight' => 15.14, 'load_capacity' => 6.7, 'cargo_volume' => 61.54, 'box_length' => 9980, 'box_width' => 2390, 'box_height' => 2580, 'door_count' => 4, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H00902', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H00904', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '99H00916', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'CLC', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '20C08678', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C08515', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C06704', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '29H19555', 'total_weight' => 15, 'load_capacity' => 7.25, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'Thiên minh', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29H19582', 'total_weight' => 15, 'load_capacity' => 7.25, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'Thiên minh', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29H19179', 'total_weight' => 15, 'load_capacity' => 7.25, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'Thiên minh', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29H19194', 'total_weight' => 15, 'load_capacity' => 7.25, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'Thiên minh', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29H19892', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'Thiên minh', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29H19883', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'Thiên minh', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '29H19900', 'total_weight' => 14.75, 'load_capacity' => 7, 'cargo_volume' => 52.53, 'box_length' => 8555, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 3, 'owner' => 'Thiên minh', 'type' => 'rent'],
            ['make' => 'HINO', 'plate' => '20C13238', 'total_weight' => 23.8, 'load_capacity' => 14.1, 'cargo_volume' => 55.12, 'box_length' => 9160, 'box_width' => 2360, 'box_height' => 2550, 'door_count' => 4, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C13005', 'total_weight' => 23.8, 'load_capacity' => 14.1, 'cargo_volume' => 55.12, 'box_length' => 9160, 'box_width' => 2360, 'box_height' => 2550, 'door_count' => 4, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C18019', 'total_weight' => 23.8, 'load_capacity' => 13.1, 'cargo_volume' => 55.54, 'box_length' => 9400, 'box_width' => 2290, 'box_height' => 2580, 'door_count' => 4, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C18325', 'total_weight' => 23.8, 'load_capacity' => 13.2, 'cargo_volume' => 57.84, 'box_length' => 9420, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 7, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C18331', 'total_weight' => 23.8, 'load_capacity' => 13.2, 'cargo_volume' => 57.84, 'box_length' => 9420, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 7, 'owner' => 'ASGT', 'type' => 'company'],
            ['make' => 'HINO', 'plate' => '20C18404', 'total_weight' => 23.8, 'load_capacity' => 13.2, 'cargo_volume' => 57.84, 'box_length' => 9420, 'box_width' => 2380, 'box_height' => 2580, 'door_count' => 7, 'owner' => 'ASGT', 'type' => 'company'],
        ];

        foreach ($vehicles as $v) {
            DB::table('vehicles')->upsert(
                [[
                    'plate_number' => $v['plate'],
                    'make' => $v['make'],
                    'total_weight' => $v['total_weight'],
                    'load_capacity' => $v['load_capacity'],
                    'cargo_volume' => $v['cargo_volume'],
                    'box_length' => $v['box_length'],
                    'box_width' => $v['box_width'],
                    'box_height' => $v['box_height'],
                    'door_count' => $v['door_count'],
                    'owner' => $v['owner'],
                    'type' => $v['type'],
                    'vehicle_type' => 'normal',
                    'fuel_type' => 'Diesel',
                    'status' => 'on',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['plate_number'],
                ['make', 'total_weight', 'load_capacity', 'cargo_volume', 'box_length', 'box_width', 'box_height', 'door_count', 'owner', 'type', 'vehicle_type', 'fuel_type', 'status', 'is_active', 'updated_at'],
            );
        }
    }
}
