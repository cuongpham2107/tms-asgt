<?php

namespace App\Services\Export;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\OrderType;
use App\Enums\VehicleOwnerType;
use App\Enums\VehicleType;
use App\Models\Order;
use App\Models\Trip;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TripExcelExportService
{
    /**
     * @param  Builder|Collection<int, Trip|Order>  $queryOrCollection
     */
    public function export(Builder|Collection $queryOrCollection, ?string $filename = null): StreamedResponse
    {
        if ($queryOrCollection instanceof Builder) {
            if ($queryOrCollection->getModel() instanceof Order) {
                return $this->exportFromOrders($queryOrCollection, $filename);
            }

            return $this->exportFromTrips($queryOrCollection, $filename);
        }

        $first = $queryOrCollection->first();
        if ($first instanceof Order) {
            /** @var Collection<int, Order> $orders */
            $orders = $queryOrCollection;

            return $this->exportFromOrders($orders, $filename);
        }

        /** @var Collection<int, Trip> $trips */
        $trips = $queryOrCollection;

        return $this->exportFromTrips($trips, $filename);
    }

    /**
     * @param  Builder|Collection<int, Trip>  $trips
     */
    public function exportFromTrips(Builder|Collection $trips, ?string $filename = null): StreamedResponse
    {
        $spreadsheet = $this->buildSpreadsheet($trips);

        $defaultFilename = 'Tong-hop-chuyen-phuc-vu-'.now()->format('Ymd-His').'.xlsx';
        $finalFilename = $filename ?: $defaultFilename;

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $finalFilename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    /**
     * @param  Builder|Collection<int, Order>  $orders
     */
    public function exportFromOrders(Builder|Collection $orders, ?string $filename = null): StreamedResponse
    {
        if ($orders instanceof Builder) {
            $orders = $orders->with([
                'customer',
                'area',
                'pickupLocation',
                'deliveryPoints.location',
                'trip.vehicle',
                'trip.driver',
                'trip.checkpoints',
                'tripCheckpoints',
            ])->get();
        }

        $trips = $this->convertOrdersToTripsCollection($orders);

        $defaultFilename = 'Tong-hop-don-hang-'.now()->format('Ymd-His').'.xlsx';
        $finalFilename = $filename ?: $defaultFilename;

        return $this->exportFromTrips($trips, $finalFilename);
    }

    /**
     * @param  Collection<int, Order>  $orders
     * @return Collection<int, Trip>
     */
    protected function convertOrdersToTripsCollection(Collection $orders): Collection
    {
        $tripMap = collect();
        $unassignedOrders = collect();

        foreach ($orders as $order) {
            if ($order->trip) {
                $tripId = $order->trip->id;
                if (! $tripMap->has($tripId)) {
                    $trip = clone $order->trip;
                    $trip->setRelation('orders', collect([$order]));
                    $tripMap->put($tripId, $trip);
                } else {
                    $tripMap->get($tripId)->orders->push($order);
                }
            } else {
                $unassignedOrders->push($order);
            }
        }

        // Tạo chuyến ảo cho đơn chưa gán chuyến để vẫn hiển thị trên bảng
        foreach ($unassignedOrders as $order) {
            $syntheticTrip = new Trip([
                'trip_code' => $order->order_code,
                'started_at' => $order->planned_loading_at,
            ]);
            $syntheticTrip->setRelation('orders', collect([$order]));
            $syntheticTrip->setRelation('checkpoints', collect());
            $syntheticTrip->setRelation('vehicle', null);
            $syntheticTrip->setRelation('driver', null);
            $tripMap->push($syntheticTrip);
        }

        return $tripMap->values();
    }

    /**
     * @param  Builder|Collection<int, Trip>  $trips
     */
    public function buildSpreadsheet(Builder|Collection $trips): Spreadsheet
    {
        if ($trips instanceof Builder) {
            $trips = $trips
                ->with([
                    'vehicle',
                    'driver',
                    'orders.customer',
                    'orders.area',
                    'orders.pickupLocation',
                    'orders.deliveryPoints.location',
                    'orders.tripCheckpoints',
                    'checkpoints',
                ])
                ->get();
        }

        $spreadsheet = new Spreadsheet;

        // Sheet 1: Bảng tổng hợp chuyến phục vụ
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('Bảng tổng hợp chuyến phục vụ');
        $this->buildTripSummarySheet($sheet1, $trips);

        // Sheet 2: Bảng dữ liệu hàng ngoài
        $sheet2 = $spreadsheet->createSheet();
        $sheet2->setTitle('Bảng dữ liệu hàng ngoài');
        $this->buildExternalCargoSheet($sheet2, $trips);

        // Trở về sheet 1 làm active sheet mặc định
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  Collection<int, Trip>  $trips
     */
    protected function buildTripSummarySheet(Worksheet $sheet, Collection $trips): void
    {
        // 1. Tiêu đề lớn
        $sheet->mergeCells('A1:AA2');
        $sheet->setCellValue('A1', 'BẢNG THỐNG KÊ TỔNG HỢP CÁC CHUYẾN ĐÃ PHỤC VỤ');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new Color('1E3A8A'));
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle('A1:AA2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('DBEAFE');

        // 2. Dòng Headers (Dòng 4)
        $headers = [
            'A4' => 'Loại hình phục vụ',
            'B4' => 'Mã đơn hàng',
            'C4' => 'Ngày',
            'D4' => 'BSX',
            'E4' => 'Hành trình',
            'F4' => 'Thời gian đóng hàng',
            'G4' => 'Thời gian xe chạy',
            'H4' => 'Thời gian đến',
            'I4' => 'Thời gian hạ hàng',
            'J4' => 'Thời gian xe đóng hàng',
            'K4' => 'Thời gian xe di chuyển',
            'L4' => 'Thời gian xe hạ hàng',
            'M4' => 'Thời gian của chuyến tính đến thời gian chờ hạ hàng 22:00',
            'N4' => 'Thời gian cho 1 chuyến hàng',
            'O4' => 'Khách hàng',
            'P4' => 'Kho đóng trả hàng',
            'Q4' => 'Quản lý xe (xe công ty or xe thuê)',
            'R4' => 'Loại xe',
            'S4' => 'Ghi chú',
            'T4' => 'Hàng quay đầu',
            'U4' => 'Họ tên lái xe',
            'V4' => 'Km có hàng',
            'W4' => 'Km không hàng',
            'X4' => 'PCS',
            'Y4' => 'GW',
            'Z4' => 'Loại hàng',
            'AA4' => 'Trọng tải tính cước',
        ];

        foreach ($headers as $cell => $title) {
            $sheet->setCellValue($cell, $title);
        }

        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => '1E40AF'], // Blue 800
            ],
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => '93C5FD']],
            ],
        ];
        $sheet->getStyle('A4:AA4')->applyFromArray($headerStyle);
        $sheet->getRowDimension(4)->setRowHeight(35);

        // 3. Đổ dữ liệu
        $row = 5;
        foreach ($trips as $trip) {
            $vehicle = $trip->vehicle;
            $driver = $trip->driver;
            $orders = $trip->orders;

            // Nếu chuyến rỗng không có đơn hàng (Empty Run)
            if ($orders->isEmpty()) {
                $checkpoints = $trip->checkpoints;
                $tA = $checkpoints->firstWhere('checkpoint_type', CheckpointType::Started)?->occurred_at;
                $tB = $tA;
                $tC = $checkpoints->firstWhere('checkpoint_type', CheckpointType::Completed)?->occurred_at ?? $trip->completed_at;
                $tD = $tC;

                $startLoc = $trip->startLocation?->code ?? '';
                $endLoc = $trip->endLocation?->code ?? '';
                $journey = trim("{$startLoc} {$endLoc}");

                $this->writeTripSummaryRow(
                    $sheet,
                    $row,
                    serviceType: 'Xe không hàng',
                    code: $trip->trip_code,
                    date: $trip->started_at,
                    plateNumber: $vehicle?->plate_number,
                    journey: $journey,
                    timeA: $tA,
                    timeB: $tB,
                    timeC: $tC,
                    timeD: $tD,
                    customer: '',
                    warehouse: $endLoc,
                    vehicleOwner: $vehicle?->type?->value ?? '',
                    vehicleType: $vehicle?->vehicle_type?->value ?? '',
                    note: $trip->note ?? '',
                    isReturnTrip: true,
                    driverName: $driver?->name ?? '',
                    kmLoaded: 0,
                    kmEmpty: (float) ($trip->total_km_empty ?? $trip->total_km ?? 0),
                    pcs: 0,
                    gw: 0,
                    cargoType: '',
                    chargeableWeight: 0
                );
                $row++;

                continue;
            }

            // Với các chuyến có đơn hàng
            foreach ($orders as $order) {
                $checkpoints = $order->tripCheckpoints->isNotEmpty() ? $order->tripCheckpoints : $trip->checkpoints;

                // Các mốc thời gian A, B, C, D
                $tA = $checkpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedPickup)?->occurred_at
                    ?? $order->planned_loading_at
                    ?? $trip->started_at;

                $tB = $checkpoints->firstWhere('checkpoint_type', CheckpointType::LeftPickup)?->occurred_at
                    ?? $trip->started_at
                    ?? $tA;

                $tC = $checkpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedDelivery)?->occurred_at
                    ?? $order->deliveryPoints->first()?->arrived_at;

                $tD = $checkpoints->where('checkpoint_type', CheckpointType::Completed)->last()?->occurred_at
                    ?? $order->deliveryPoints->last()?->delivered_at
                    ?? $trip->completed_at;

                // Hành trình viết tắt
                $journey = $this->buildJourneyString($order);

                // Kho đóng trả hàng
                $warehouse = $order->deliveryPoints->first()?->location?->code
                    ?? $order->pickupLocation?->code
                    ?? '';

                $serviceType = $order->type === OrderType::Hhhk ? 'HHHK' : 'Hàng ngoài';

                $this->writeTripSummaryRow(
                    $sheet,
                    $row,
                    serviceType: $serviceType,
                    code: $order->order_code ?: $trip->trip_code,
                    date: $trip->started_at ?? $order->planned_loading_at,
                    plateNumber: $vehicle?->plate_number,
                    journey: $journey,
                    timeA: $tA,
                    timeB: $tB,
                    timeC: $tC,
                    timeD: $tD,
                    customer: $order->customer?->code ?? $order->customer?->name ?? '',
                    warehouse: $warehouse,
                    vehicleOwner: $vehicle?->type?->value ?? '',
                    vehicleType: $vehicle?->vehicle_type?->value ?? '',
                    note: $order->notes ?? $trip->note ?? '',
                    isReturnTrip: (bool) $order->is_return_trip,
                    driverName: $driver?->name ?? '',
                    kmLoaded: (float) ($order->loaded_km ?? $trip->total_km_loaded ?? 0),
                    kmEmpty: (float) ($trip->total_km_empty ?? 0),
                    pcs: (int) ($order->total_packages ?? 0),
                    gw: (float) ($order->total_weight ?? 0),
                    cargoType: $order->cargo_type?->value ?? ($order->cargo_type instanceof CargoType ? $order->cargo_type->getLabel() : ''),
                    chargeableWeight: (float) ($order->chargeable_weight ?? 0)
                );
                $row++;
            }
        }

        // Định dạng viền và căn chỉnh toàn bộ bảng
        $lastRow = max(5, $row - 1);
        $dataRange = "A5:AA{$lastRow}";
        $sheet->getStyle($dataRange)->applyFromArray([
            'borders' => [
                'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'E2E8F0']],
            ],
            'font' => ['size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Auto width các cột
        foreach (range('A', 'Z') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        $sheet->getColumnDimension('AA')->setAutoSize(true);
    }

    protected function writeTripSummaryRow(
        Worksheet $sheet,
        int $row,
        string $serviceType,
        string $code,
        ?Carbon $date,
        ?string $plateNumber,
        string $journey,
        ?Carbon $timeA,
        ?Carbon $timeB,
        ?Carbon $timeC,
        ?Carbon $timeD,
        string $customer,
        string $warehouse,
        string $vehicleOwner,
        string $vehicleType,
        string $note,
        bool $isReturnTrip,
        string $driverName,
        float $kmLoaded,
        float $kmEmpty,
        int $pcs,
        float $gw,
        string $cargoType,
        float $chargeableWeight
    ): void {
        // Tính toán các mốc thời gian phút
        $time1 = ($timeA && $timeB) ? (int) abs($timeB->diffInMinutes($timeA)) : null;
        $time2 = ($timeB && $timeC) ? (int) abs($timeC->diffInMinutes($timeB)) : null;
        $time3 = ($timeC && $timeD) ? (int) abs($timeD->diffInMinutes($timeC)) : null;

        // Thời gian cắt 22:00: Nếu thời gian hạ hàng D >= 22:00
        $time4 = 0;
        if ($timeC && $timeD && ($timeD->hour >= 22 || $timeD->isAfter($timeC->copy()->endOfDay()))) {
            $cutoff22 = $timeC->copy()->setTime(22, 0, 0);
            $waitTo22 = (int) abs($cutoff22->diffInMinutes($timeC));
            $time4 = ($time1 ?? 0) + ($time2 ?? 0) + $waitTo22;
        }

        $time5 = ($time1 !== null || $time2 !== null || $time3 !== null)
            ? (($time1 ?? 0) + ($time2 ?? 0) + ($time3 ?? 0))
            : null;

        $ownerLabel = match ($vehicleOwner) {
            'company', VehicleOwnerType::Company->value => 'Xe công ty',
            'rent', VehicleOwnerType::Rent->value => 'Xe thuê',
            default => $vehicleOwner,
        };

        $typeLabel = match ($vehicleType) {
            'normal', VehicleType::Normal->value => 'Xe thường',
            'cold', VehicleType::Cold->value => 'Xe lạnh',
            'container', VehicleType::Container->value => 'Container',
            default => $vehicleType ?: 'Xe thường',
        };

        $sheet->setCellValue("A{$row}", $serviceType);
        $sheet->setCellValue("B{$row}", $code);
        $sheet->setCellValue("C{$row}", $date ? $date->format('d/m/Y') : '');
        $sheet->setCellValue("D{$row}", $plateNumber ?? '');
        $sheet->setCellValue("E{$row}", $journey);
        $sheet->setCellValue("F{$row}", $timeA ? $timeA->format('d/m/Y H:i') : '');
        $sheet->setCellValue("G{$row}", $timeB ? $timeB->format('d/m/Y H:i') : '');
        $sheet->setCellValue("H{$row}", $timeC ? $timeC->format('d/m/Y H:i') : '');
        $sheet->setCellValue("I{$row}", $timeD ? $timeD->format('d/m/Y H:i') : '');

        $sheet->setCellValue("J{$row}", $time1 ?? '');
        $sheet->setCellValue("K{$row}", $time2 ?? '');
        $sheet->setCellValue("L{$row}", $time3 ?? '');
        $sheet->setCellValue("M{$row}", $time4 > 0 ? $time4 : 0);
        $sheet->setCellValue("N{$row}", $time5 ?? '');

        $sheet->setCellValue("O{$row}", $customer);
        $sheet->setCellValue("P{$row}", $warehouse);
        $sheet->setCellValue("Q{$row}", $ownerLabel);
        $sheet->setCellValue("R{$row}", $typeLabel);
        $sheet->setCellValue("S{$row}", $note);
        $sheet->setCellValue("T{$row}", $isReturnTrip ? 'Hàng quay đầu' : '');
        $sheet->setCellValue("U{$row}", $driverName);
        $sheet->setCellValue("V{$row}", $kmLoaded > 0 ? $kmLoaded : '');
        $sheet->setCellValue("W{$row}", $kmEmpty > 0 ? $kmEmpty : '');
        $sheet->setCellValue("X{$row}", $pcs > 0 ? $pcs : '');
        $sheet->setCellValue("Y{$row}", $gw > 0 ? $gw : '');
        $sheet->setCellValue("Z{$row}", $cargoType);
        $sheet->setCellValue("AA{$row}", $chargeableWeight > 0 ? $chargeableWeight : '');

        // Căn giữa các cột mã, ngày, BSX, thời gian
        $sheet->getStyle("A{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("F{$row}:N{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("Q{$row}:R{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("T{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("V{$row}:AA{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /**
     * @param  Collection<int, Trip>  $trips
     */
    protected function buildExternalCargoSheet(Worksheet $sheet, Collection $trips): void
    {
        // Lấy tất cả đơn hàng ngoài từ các chuyến
        $externalOrders = $trips->flatMap(fn (Trip $trip) => $trip->orders)
            ->filter(fn (Order $order) => $order->type === OrderType::External || $order->type?->value === 'external');

        $singlePointOrders = $externalOrders->filter(fn (Order $o) => $o->deliveryPoints->count() <= 1);
        $multiPointOrders = $externalOrders->filter(fn (Order $o) => $o->deliveryPoints->count() > 1);

        $headers = [
            'Tên khách hàng',
            'Mã đơn hàng',
            'Ngày phát sinh',
            'BSX',
            'Điểm đóng hàng',
            'Điểm trả hàng',
            'Số Pallet',
            'Loại hàng',
            'Trọng tải tính cước',
            'Thời gian yêu cầu',
            'Thời gian đóng hàng',
            'Thời gian xe chạy',
            'Thời gian đến',
            'Thời gian hạ hàng',
            'Thời gian xe đóng hàng',
            'Thời gian xe di chuyển',
            'Thời gian xe hạ hàng',
            'Ghi chú',
            'Hình ảnh chứng từ',
            'Hàng quay đầu',
        ];

        // --- KHỐI 1: ĐƠN 1 ĐIỂM ---
        $sheet->mergeCells('A1:T1');
        $sheet->setCellValue('A1', 'DỮ LIỆU ĐIỂM ĐÓNG VÀ TRẢ: 1 ĐIỂM');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(11)->setColor(new Color('065F46'));
        $sheet->getStyle('A1:T1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('D1FAE5'); // Green 100
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Header khối 1 (Dòng 2)
        $colIdx = 1;
        foreach ($headers as $h) {
            $colLetter = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->setCellValue("{$colLetter}2", $h);
            $colIdx++;
        }
        $sheet->getStyle('A2:T2')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => '047857']], // Emerald 700
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'A7F3D0']]],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(30);

        $row = 3;
        foreach ($singlePointOrders as $order) {
            $this->writeExternalOrderRow($sheet, $row, $order);
            $row++;
        }

        if ($singlePointOrders->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'Không có dữ liệu');
            $sheet->mergeCells("A{$row}:T{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $singleEndRow = $row - 1;
        $sheet->getStyle("A3:T{$singleEndRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'E2E8F0']]],
            'font' => ['size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        // Dòng cách
        $row++;

        // --- KHỐI 2: ĐƠN NHIỀU ĐIỂM ---
        $bannerRow = $row;
        $sheet->mergeCells("A{$bannerRow}:T{$bannerRow}");
        $sheet->setCellValue("A{$bannerRow}", 'DỮ LIỆU ĐIỂM ĐÓNG VÀ TRẢ:  NHIỀU ĐIỂM');
        $sheet->getStyle("A{$bannerRow}")->getFont()->setBold(true)->setSize(11)->setColor(new Color('92400E'));
        $sheet->getStyle("A{$bannerRow}:T{$bannerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FEF3C7'); // Amber 100
        $sheet->getStyle("A{$bannerRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension($bannerRow)->setRowHeight(25);

        // Header khối 2
        $headerRow2 = $bannerRow + 1;
        $colIdx = 1;
        foreach ($headers as $h) {
            $colLetter = Coordinate::stringFromColumnIndex($colIdx);
            $sheet->setCellValue("{$colLetter}{$headerRow2}", $h);
            $colIdx++;
        }
        $sheet->getStyle("A{$headerRow2}:T{$headerRow2}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 9],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'B45309']], // Amber 700
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FDE68A']]],
        ]);
        $sheet->getRowDimension($headerRow2)->setRowHeight(30);

        $row = $headerRow2 + 1;
        foreach ($multiPointOrders as $order) {
            $row = $this->writeMultiPointOrderRows($sheet, $row, $order);
        }

        if ($multiPointOrders->isEmpty()) {
            $sheet->setCellValue("A{$row}", 'Không có dữ liệu');
            $sheet->mergeCells("A{$row}:T{$row}");
            $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $row++;
        }

        $multiEndRow = $row - 1;
        $sheet->getStyle("A{$headerRow2}:T{$multiEndRow}")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'E2E8F0']]],
            'font' => ['size' => 9],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        foreach (range('A', 'T') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }

    protected function writeExternalOrderRow(Worksheet $sheet, int $row, Order $order): void
    {
        $trip = $order->trip;
        $vehicle = $trip?->vehicle;
        $checkpoints = $order->tripCheckpoints->isNotEmpty() ? $order->tripCheckpoints : ($trip?->checkpoints ?? collect());

        $deliveryPoint = $order->deliveryPoints->first();

        $tReq = $order->planned_loading_at;
        $tA = $checkpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedPickup)?->occurred_at ?? $tReq;
        $tB = $checkpoints->firstWhere('checkpoint_type', CheckpointType::LeftPickup)?->occurred_at ?? $trip?->started_at ?? $tA;
        $tC = $checkpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedDelivery)?->occurred_at ?? $deliveryPoint?->arrived_at;
        $tD = $checkpoints->where('checkpoint_type', CheckpointType::Completed)->last()?->occurred_at ?? $deliveryPoint?->delivered_at ?? $trip?->completed_at;

        $durLoading = ($tA && $tB) ? (int) abs($tB->diffInMinutes($tA)) : null;
        $durTravel = ($tB && $tC) ? (int) abs($tC->diffInMinutes($tB)) : null;
        $durUnloading = ($tC && $tD) ? (int) abs($tD->diffInMinutes($tC)) : null;

        $pickupText = $order->pickupLocation?->name ?: ($order->pickup_address ?: ($order->pickupLocation?->code ?? ''));
        $deliveryText = $deliveryPoint?->location?->name ?: ($deliveryPoint?->address ?: ($deliveryPoint?->location?->code ?? ''));

        $sheet->setCellValue("A{$row}", $order->customer?->code ?? $order->customer?->name ?? '');
        $sheet->setCellValue("B{$row}", $order->order_code);
        $sheet->setCellValue("C{$row}", $order->planned_loading_at ? $order->planned_loading_at->format('d/m/Y') : '');
        $sheet->setCellValue("D{$row}", $vehicle?->plate_number ?? '');
        $sheet->setCellValue("E{$row}", $pickupText);
        $sheet->setCellValue("F{$row}", $deliveryText);
        $sheet->setCellValue("G{$row}", $order->total_packages ? "{$order->total_packages} Kiện" : '');
        $sheet->setCellValue("H{$row}", $order->cargo_type?->value ?? ($order->cargo_type instanceof CargoType ? $order->cargo_type->getLabel() : ''));
        $sheet->setCellValue("I{$row}", $order->chargeable_weight ? (float) $order->chargeable_weight : '');
        $sheet->setCellValue("J{$row}", $tReq ? $tReq->format('d/m/Y H:i') : '');
        $sheet->setCellValue("K{$row}", $tA ? $tA->format('d/m/Y H:i') : '');
        $sheet->setCellValue("L{$row}", $tB ? $tB->format('d/m/Y H:i') : '');
        $sheet->setCellValue("M{$row}", $tC ? $tC->format('d/m/Y H:i') : '');
        $sheet->setCellValue("N{$row}", $tD ? $tD->format('d/m/Y H:i') : '');
        $sheet->setCellValue("O{$row}", $durLoading ?? '');
        $sheet->setCellValue("P{$row}", $durTravel ?? '');
        $sheet->setCellValue("Q{$row}", $durUnloading ?? '');
        $sheet->setCellValue("R{$row}", $order->notes ?? '');
        $sheet->setCellValue("S{$row}", '');
        $sheet->setCellValue("T{$row}", $order->is_return_trip ? 'Hàng quay đầu' : '');

        $sheet->getStyle("B{$row}:D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("J{$row}:Q{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("I{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    protected function writeMultiPointOrderRows(Worksheet $sheet, int $startRow, Order $order): int
    {
        $trip = $order->trip;
        $vehicle = $trip?->vehicle;
        $checkpoints = $order->tripCheckpoints->isNotEmpty() ? $order->tripCheckpoints : ($trip?->checkpoints ?? collect());

        $deliveryPoints = $order->deliveryPoints->sortBy('sequence')->values();
        $pickupText = $order->pickupLocation?->name ?: ($order->pickup_address ?: ($order->pickupLocation?->code ?? ''));

        $currentRow = $startRow;
        foreach ($deliveryPoints as $idx => $dp) {
            $isFirst = ($idx === 0);
            $isLast = ($idx === $deliveryPoints->count() - 1);

            $dpCheckpoints = $checkpoints->where('delivery_point_id', $dp->id);

            $tReq = $order->planned_loading_at;
            $tA = $isFirst
                ? ($checkpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedPickup)?->occurred_at ?? $tReq)
                : null;
            $tB = $isFirst
                ? ($checkpoints->firstWhere('checkpoint_type', CheckpointType::LeftPickup)?->occurred_at ?? $trip?->started_at ?? $tA)
                : null;

            $tC = $dpCheckpoints->firstWhere('checkpoint_type', CheckpointType::ArrivedDelivery)?->occurred_at
                ?? $dp->arrived_at;
            $tD = $dpCheckpoints->where('checkpoint_type', CheckpointType::Completed)->last()?->occurred_at
                ?? $dp->delivered_at;

            $durLoading = ($tA && $tB) ? (int) abs($tB->diffInMinutes($tA)) : null;
            $durTravel = ($tB && $tC) ? (int) abs($tC->diffInMinutes($tB)) : null;
            $durUnloading = ($tC && $tD) ? (int) abs($tD->diffInMinutes($tC)) : null;

            $deliveryText = $dp->location?->name ?: ($dp->address ?: ($dp->location?->code ?? ''));

            $sheet->setCellValue("A{$currentRow}", $order->customer?->code ?? $order->customer?->name ?? '');
            $sheet->setCellValue("B{$currentRow}", $order->order_code);
            $sheet->setCellValue("C{$currentRow}", $order->planned_loading_at ? $order->planned_loading_at->format('d/m/Y') : '');
            $sheet->setCellValue("D{$currentRow}", $isFirst ? ($vehicle?->plate_number ?? '') : '');
            $sheet->setCellValue("E{$currentRow}", $isFirst ? $pickupText : '');
            $sheet->setCellValue("F{$currentRow}", $deliveryText);
            $sheet->setCellValue("G{$currentRow}", $dp->total_packages ? "{$dp->total_packages} Kiện" : ($isFirst && $order->total_packages ? "{$order->total_packages} Kiện" : ''));
            $sheet->setCellValue("H{$currentRow}", $order->cargo_type?->value ?? ($order->cargo_type instanceof CargoType ? $order->cargo_type->getLabel() : ''));
            $sheet->setCellValue("I{$currentRow}", $dp->total_weight ?: ($isFirst ? (float) $order->chargeable_weight : ''));
            $sheet->setCellValue("J{$currentRow}", $tReq ? $tReq->format('d/m/Y H:i') : '');
            $sheet->setCellValue("K{$currentRow}", $tA ? $tA->format('d/m/Y H:i') : '');
            $sheet->setCellValue("L{$currentRow}", $tB ? $tB->format('d/m/Y H:i') : '');
            $sheet->setCellValue("M{$currentRow}", $tC ? $tC->format('d/m/Y H:i') : '');
            $sheet->setCellValue("N{$currentRow}", $tD ? $tD->format('d/m/Y H:i') : '');
            $sheet->setCellValue("O{$currentRow}", $durLoading ?? '');
            $sheet->setCellValue("P{$currentRow}", $durTravel ?? '');
            $sheet->setCellValue("Q{$currentRow}", $durUnloading ?? '');
            $sheet->setCellValue("R{$currentRow}", $isFirst ? ($order->notes ?? '') : '');
            $sheet->setCellValue("S{$currentRow}", '');
            $sheet->setCellValue("T{$currentRow}", ($isLast && $order->is_return_trip) ? 'Hàng quay đầu' : '');

            $sheet->getStyle("B{$currentRow}:D{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("J{$currentRow}:Q{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("I{$currentRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

            $currentRow++;
        }

        return $currentRow;
    }

    protected function buildJourneyString(Order $order): string
    {
        $pickupCode = $order->pickupLocation?->code;
        if (! $pickupCode && $order->pickup_address) {
            $pickupCode = $this->extractLocationShortName($order->pickup_address);
        }

        $deliveryPoints = $order->deliveryPoints;
        if ($deliveryPoints->isEmpty()) {
            return trim((string) $pickupCode);
        }

        if ($deliveryPoints->count() === 1) {
            $dp = $deliveryPoints->first();
            $delivCode = $dp?->location?->code ?: $this->extractLocationShortName($dp?->address ?? '');

            return trim("{$pickupCode} {$delivCode}");
        }

        // Với đơn nhiều điểm: Ghép điểm đóng đầu tiên + điểm trả cuối cùng
        $lastDp = $deliveryPoints->sortBy('sequence')->last();
        $lastCode = $lastDp?->location?->code ?: $this->extractLocationShortName($lastDp?->address ?? '');

        return trim("{$pickupCode} {$lastCode}");
    }

    protected function extractLocationShortName(string $address): string
    {
        if (preg_match('/KCN\s+([^,]+)/iu', $address, $m)) {
            return trim($m[1]);
        }

        $parts = array_map('trim', explode(',', $address));
        $count = count($parts);

        if ($count >= 2) {
            return $parts[$count - 2];
        }

        return $parts[0] ?? $address;
    }
}
