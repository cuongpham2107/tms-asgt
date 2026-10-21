<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('vehicles:update-specs')]
#[Description('Cập nhật thông số 69 xe (Số cửa, Ghi chú, Tải thực, Số khối)')]
class UpdateVehicleSpecsCommand extends Command
{
    /**
     * Danh sách 69 xe từ bảng người dùng cung cấp.
     */
    protected array $vehiclesData = [
        ['plate' => '20C-04640', 'ton' => '5T', 'door' => 1, 'note' => null, 'load' => 4.115, 'volume' => 42.79],
        ['plate' => '20C-08846', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '20C-13642', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '20C-04357', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 4.560, 'volume' => 42.79],
        ['plate' => '20C-04581', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.055, 'volume' => 42.79],
        ['plate' => '20C-04937', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.045, 'volume' => 42.79],
        ['plate' => '20C-05152', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.760, 'volume' => 42.79],
        ['plate' => '20C-04240', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.025, 'volume' => 42.79],
        ['plate' => '20C-09032', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '20C-06759', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '20C-04607', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.005, 'volume' => 42.79],
        ['plate' => '20C-04368', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.105, 'volume' => 42.79],
        ['plate' => '20C-04820', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.035, 'volume' => 42.79],
        ['plate' => '20C-13806', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '20C-08775', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '99H-00948', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '99H-00988', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.650, 'volume' => 41.48],
        ['plate' => '99H-00973', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.650, 'volume' => 41.48],
        ['plate' => '99H-00933', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.650, 'volume' => 41.48],
        ['plate' => '29E-08245', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '29E-12041', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '29H-72338', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.650, 'volume' => 41.48],
        ['plate' => '29E-12490', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '29E-12091', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '29E-09564', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 4.750, 'volume' => 40.18],
        ['plate' => '29E-09695', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '29E-08433', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '29E-12067', 'ton' => '5T', 'door' => 3, 'note' => null, 'load' => 5.250, 'volume' => 40.34],
        ['plate' => '20C-05328', 'ton' => '5T', 'door' => 1, 'note' => 'mâm', 'load' => 5.305, 'volume' => 57.72],
        ['plate' => '20C-05592', 'ton' => '5T', 'door' => 1, 'note' => 'mâm', 'load' => 5.305, 'volume' => 57.72],
        ['plate' => '20C-05656', 'ton' => '5T', 'door' => 1, 'note' => 'mâm', 'load' => 5.305, 'volume' => 57.72],
        ['plate' => '20C-04429', 'ton' => '5T', 'door' => 1, 'note' => 'mâm', 'load' => 5.500, 'volume' => 57.72],
        ['plate' => '20C-05338', 'ton' => '5T', 'door' => 1, 'note' => 'mâm', 'load' => 5.305, 'volume' => 57.72],
        ['plate' => '20C-04427', 'ton' => '5T', 'door' => 1, 'note' => 'mâm', 'load' => 5.500, 'volume' => 57.72],
        ['plate' => '29E-12452', 'ton' => '7T', 'door' => 5, 'note' => null, 'load' => 6.800, 'volume' => 43.33],
        ['plate' => '29C-20161', 'ton' => '7T', 'door' => 5, 'note' => null, 'load' => 6.650, 'volume' => 44.11],
        ['plate' => '99H-00968', 'ton' => '7T', 'door' => 5, 'note' => null, 'load' => 6.800, 'volume' => 43.33],
        ['plate' => '99H-00944', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 6.650, 'volume' => 44.11],
        ['plate' => '20C-08678', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.000, 'volume' => 52.53],
        ['plate' => '20C-06704', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.000, 'volume' => 52.53],
        ['plate' => '20C-08515', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.000, 'volume' => 52.53],
        ['plate' => '29H-19582', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.250, 'volume' => 52.53],
        ['plate' => '29H-19555', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.250, 'volume' => 52.53],
        ['plate' => '29H-19179', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.250, 'volume' => 52.53],
        ['plate' => '29H-19194', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.250, 'volume' => 52.53],
        ['plate' => '29H-19892', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.000, 'volume' => 52.53],
        ['plate' => '29H-19883', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.000, 'volume' => 52.53],
        ['plate' => '29H-19900', 'ton' => '7T', 'door' => 3, 'note' => null, 'load' => 7.000, 'volume' => 52.53],
        ['plate' => '99H-00916', 'ton' => '7T', 'door' => 3, 'note' => 'cool', 'load' => 7.000, 'volume' => 52.50],
        ['plate' => '99H-00902', 'ton' => '7T', 'door' => 3, 'note' => 'cool', 'load' => 7.000, 'volume' => 52.50],
        ['plate' => '99H-00904', 'ton' => '7T', 'door' => 3, 'note' => 'cool', 'load' => 7.000, 'volume' => 52.50],
        ['plate' => '29H-81641', 'ton' => '7T', 'door' => 4, 'note' => 'dài', 'load' => 6.700, 'volume' => 61.50],
        ['plate' => '99H-01552', 'ton' => '7T', 'door' => 4, 'note' => 'dài', 'load' => 6.700, 'volume' => 61.50],
        ['plate' => '99H-01503', 'ton' => '7T', 'door' => 4, 'note' => 'dài', 'load' => 6.700, 'volume' => 61.50],
        ['plate' => '99H-01427', 'ton' => '7T', 'door' => 4, 'note' => 'dài', 'load' => 6.700, 'volume' => 61.50],
        ['plate' => '99H-01464', 'ton' => '7T', 'door' => 4, 'note' => 'dài', 'load' => 6.700, 'volume' => 61.50],
        ['plate' => '20C-13238', 'ton' => '14T', 'door' => 4, 'note' => null, 'load' => 14.100, 'volume' => 55.12],
        ['plate' => '20H-08390', 'ton' => '7T', 'door' => 4, 'note' => 'dài', 'load' => 7.000, 'volume' => 59.48],
        ['plate' => '20H-09163', 'ton' => '7T', 'door' => 4, 'note' => 'dài', 'load' => 7.000, 'volume' => 59.48],
        ['plate' => '20H-09167', 'ton' => '7T', 'door' => 4, 'note' => 'dài', 'load' => 7.000, 'volume' => 59.48],
        ['plate' => '20C-13005', 'ton' => '14T', 'door' => 4, 'note' => null, 'load' => 14.100, 'volume' => 55.12],
        ['plate' => '20C-18019', 'ton' => '14T', 'door' => 4, 'note' => null, 'load' => 13.100, 'volume' => 55.54],
        ['plate' => '20C-18325', 'ton' => '14T', 'door' => 7, 'note' => null, 'load' => 13.200, 'volume' => 57.84],
        ['plate' => '20C-18331', 'ton' => '14T', 'door' => 7, 'note' => null, 'load' => 13.200, 'volume' => 57.84],
        ['plate' => '20C-18404', 'ton' => '14T', 'door' => 7, 'note' => null, 'load' => 13.200, 'volume' => 57.84],
        ['plate' => '20H-05307', 'ton' => '3.5T', 'door' => 3, 'note' => null, 'load' => 3.240, 'volume' => 28.17],
        ['plate' => '20H-05312', 'ton' => '3.5T', 'door' => 3, 'note' => null, 'load' => 3.900, 'volume' => 28.17],
        ['plate' => '20H-05304', 'ton' => '3.5T', 'door' => 3, 'note' => null, 'load' => 3.165, 'volume' => 28.17],
        ['plate' => '20H-04842', 'ton' => '3.5T', 'door' => 3, 'note' => null, 'load' => 3.195, 'volume' => 28.17],
    ];

    public function handle(): int
    {
        $this->info('Đang bắt đầu cập nhật thông số cho 69 phương tiện...');

        $updatedCount = 0;
        $createdCount = 0;

        foreach ($this->vehiclesData as $item) {
            $cleanPlate = preg_replace('/[^A-Za-z0-9]/', '', $item['plate']);
            $vehicle = Vehicle::where('plate_number', $cleanPlate)
                ->orWhere('plate_number', $item['plate'])
                ->first();

            // Format chuỗi ghi chú hiển thị: Số cửa + Ghi chú (VD: "3C", "1C mâm", "3C cool", "4C dài")
            $doorText = $item['door'] ? "{$item['door']}C" : '';
            $noteText = trim("{$doorText} {$item['note']}");

            $payload = [
                'door_count' => $item['door'],
                'load_capacity' => $item['load'],
                'cargo_volume' => $item['volume'],
                'notes' => $noteText,
            ];

            if ($vehicle) {
                $vehicle->update($payload);
                $updatedCount++;
            } else {
                Vehicle::create(array_merge($payload, [
                    'plate_number' => $cleanPlate,
                    'owner' => 'ASGT',
                    'type' => 'company',
                    'status' => 'on',
                    'is_active' => true,
                    'current_mileage' => 10000,
                ]));
                $createdCount++;
            }
        }

        $this->info("Đã hoàn thành! Đã cập nhật: {$updatedCount} xe, Tạo mới: {$createdCount} xe.");

        return self::SUCCESS;
    }
}
