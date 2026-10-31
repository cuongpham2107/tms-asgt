<?php

use App\Enums\VehicleType;
use App\Models\BillingZone;
use App\Models\Customer;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\WaitingFeeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * Tạo file Excel thu nhỏ có cùng bố cục các sheet bảng giá thật.
 *
 * @param  array<string, array<string, mixed>>  $sheets  tên sheet => [ô => giá trị]
 */
function makeFreightWorkbook(array $sheets): string
{
    $spreadsheet = new Spreadsheet;
    $spreadsheet->removeSheetByIndex(0);

    foreach ($sheets as $title => $cells) {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($title);
        foreach ($cells as $coordinate => $value) {
            $sheet->setCellValue($coordinate, $value);
        }
    }

    $path = tempnam(sys_get_temp_dir(), 'rates').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

beforeEach(function () {
    $this->safo = Customer::create(['code' => 'ASGS (SAFO)', 'name' => 'SAFO', 'is_active' => true]);
    $this->alse = Customer::create(['code' => 'ALSE', 'name' => 'ALSE', 'is_active' => true]);
    $this->vfc = Customer::create(['code' => 'ASGS (VFC)', 'name' => 'VFC', 'is_active' => true]);

    $this->fukang = Location::create(['code' => 'FUKANG', 'name' => 'Fukang', 'loc_type' => 'pickup', 'is_active' => true]);
    $this->alsc = Location::create(['code' => 'ALSC', 'name' => 'Nhà ga ALSC', 'loc_type' => 'delivery', 'is_active' => true]);
    $this->alseWarehouse = Location::create(['code' => 'ALSE', 'name' => 'Kho ALSE', 'loc_type' => 'delivery', 'is_active' => true]);
    $this->akzo = Location::create(['code' => 'AKZO', 'name' => 'Nhà máy AkzoNobel', 'loc_type' => 'pickup', 'is_active' => true]);
    $this->srTech = Location::create(['code' => 'SÔNG CÔNG', 'name' => 'CÔNG TY TNHH SR TECH', 'loc_type' => 'pickup', 'is_active' => true]);
    $this->enkei = Location::create(['code' => 'ENKEI', 'name' => 'Enkei', 'loc_type' => 'delivery', 'is_active' => true]);

    $this->file = makeFreightWorkbook([
        '5. Địa điểm' => [
            'C2' => 'FUKANG', 'D2' => 'KCN QUANG CHÂU', 'J2' => 'Kcn Quang Châu',
            'C3' => 'ALSC', 'D3' => 'NỘI BÀI',
            'C4' => 'ALSE', 'D4' => 'KCN VSIP BẮC NINH',
            'C5' => 'AKZO', 'D5' => 'KCN QUẾ VÕ 1',
            'C6' => 'ENKEI', 'D6' => 'KCN THĂNG LONG',
            // Mã trong hệ thống ("SÔNG CÔNG") khác mã Excel ("SR TECH") → khớp theo tên công ty cột E
            'C7' => 'SR TECH', 'D7' => 'KCN SÔNG CÔNG', 'E7' => 'CÔNG TY TNHH SR TECH', 'F7' => 'KCN Sông Công I, Bách Quang, Thái Nguyên',
        ],
        '3.1 Đơn giá 1 điểm trả' => [
            'J1' => 1.25, 'K1' => 1.5, 'L1' => 2.5, 'M1' => 3.5, 'N1' => 5, 'O1' => 7, 'P1' => 8, 'Q1' => 10, 'R1' => 14, 'S1' => '10 CÁNH DƠI',
            'B2' => 'GCR', 'D2' => 'ASGS (SAFO)', 'E2' => 'Kcn Quang Châu', 'F2' => 'Nội Bài',
            'J2' => 750000, 'N2' => 1300000, 'Q2' => 0, 'S2' => 2600000,
            // Cụm chưa có địa điểm nào → vẫn nhập giá, báo lại để gán cụm cho địa điểm
            'B3' => 'GCR', 'D3' => 'ASGS (SAFO)', 'E3' => 'Kcn Quang Châu', 'F3' => 'Kcn Yên Bình', 'J3' => 900000,
        ],
        '3.4 Đơn giá 2 điểm trả ALSE' => [
            'L1' => 1.25, 'M1' => 5, 'N1' => 8, 'O1' => 10,
            'D2' => 'ALSE', 'E2' => 'Kcn Quang Châu', 'F2' => 'Kcn Vsip Bắc Ninh', 'G2' => 'Nội Bài',
            'N2' => 1520000,
        ],
        '3.2 Đơn giá VFC Lạnh' => [
            'G1' => 1.25, 'H1' => 2.5, 'I1' => 3.5, 'J1' => 5, 'K1' => 7, 'L1' => 8, 'M1' => 10, 'N1' => 15,
            'A2' => 'Đông Anh', 'B2' => 'Lạnh', 'C2' => 'VFC', 'G2' => 727500,
        ],
        '3.3 Đơn giá VFC Thường' => [
            'G4' => 'Dưới 2T', 'H4' => 'Từ 2T đến 3T', 'I4' => 'Từ 3T đến 4T', 'J4' => 'Từ 4T đến 7T',
            'A5' => 'Quế Võ Thăng Long', 'B5' => 'Thường', 'C5' => 'VFC', 'G5' => 741280.79, 'J5' => 1563851.46,
        ],
        '6.1 VFC code tìm giá thường' => [
            'B2' => 'VFC', 'D2' => 'Kcn Quế Võ 1', 'E2' => 'Kcn Thăng Long', 'F2' => 'quế võ thăng long',
        ],
        '6.2 VFC code tìm giá lạnh' => [
            'C3' => 'VFC', 'E3' => 'Kcn Quế Võ 1', 'F3' => 'Kcn Thăng Long', 'G3' => 'Đông Anh',
        ],
        '4. Phụ phí' => [
            'F1' => 1.25, 'G1' => 1.5, 'H1' => 2.5, 'I1' => 3.5, 'J1' => 5, 'K1' => 7, 'L1' => 8, 'M1' => 10, 'N1' => 14,
            'C2' => 'ASGS (SAFO)', 'D2' => 'Phí giờ chờ', 'E2' => 'VNĐ/giờ', 'F2' => 50000, 'J2' => 100000,
            'C3' => 'ASGS (SAFO)', 'D3' => 'Miễn phí', 'E3' => 'Giờ (h)', 'F3' => 2, 'J3' => 2,
            'B4' => 'Hàng lạnh', 'C4' => 'ASGS (VFC)', 'D4' => 'Phí giờ chờ nổ máy', 'E4' => 'VNĐ/giờ', 'F4' => 100000,
            'B5' => 'Hàng lạnh', 'C5' => 'ASGS (VFC)', 'D5' => 'Miễn phí nổ máy', 'E5' => 'Giờ (h)', 'F5' => 0.5,
            'A44' => 'CODE', 'B44' => 'Loại hình', 'C44' => 'Khách hàng', 'D44' => 'Thời gian miễn thêm',
            'C45' => 'ASGS (SAFO)', 'D45' => 29,
        ],
    ]);
});

afterEach(function () {
    @unlink($this->file);
});

test('imports freight rates and waiting fees from the price workbook', function () {
    $this->artisan('freight-rates:import', ['--file' => $this->file])
        ->expectsOutputToContain('KCN YÊN BÌNH')
        ->assertSuccessful();

    // Sheet 5: cột D là tên khu vực (mã tự tạo từ tên), gán cho địa điểm theo mã nhà máy cột C
    $quangChau = BillingZone::where('name', 'KCN QUANG CHÂU')->firstOrFail();
    $noiBai = BillingZone::where('name', 'NỘI BÀI')->firstOrFail();
    expect($quangChau->code)->toBe('KCN-QUANG-CHAU')
        ->and($this->fukang->fresh()->billing_zone_id)->toBe($quangChau->id)
        ->and($this->alsc->fresh()->billing_zone_id)->toBe($noiBai->id)
        ->and($this->srTech->fresh()->billingZone->name)->toBe('KCN SÔNG CÔNG');

    $safoRates = FreightRate::where('customer_id', $this->safo->id)
        ->where('origin_zone_id', $quangChau->id)
        ->where('destination_zone_id', $noiBai->id)
        ->get();

    // 1 dòng giá thường (ô 10T = 0 bị bỏ qua) + 1 dòng xe cánh dơi cho cột "10 CÁNH DƠI"
    $normal = $safoRates->firstWhere('vehicle_type', null);
    expect($safoRates)->toHaveCount(2)
        ->and($normal->prices)->toEqual(['1.25' => 750_000, '5' => 1_300_000])
        ->and($safoRates->firstWhere('vehicle_type', VehicleType::BatWing)?->priceFor(10))->toBe(2_600_000.0);

    // Giá 2 điểm trả: tuyến điểm đi → điểm trả đầu, loại hình "2 điểm trả"
    $twoDrop = FreightRate::where('customer_id', $this->alse->id)->firstOrFail();
    expect($twoDrop->rate_type)->toBe('2 điểm trả')
        ->and($twoDrop->destinationZone->name)->toBe('KCN VSIP BẮC NINH')
        ->and($twoDrop->priceFor(8))->toBe(1_520_000.0);

    $vfcCold = FreightRate::where('customer_id', $this->vfc->id)->where('rate_type', 'Hàng lạnh')->firstOrFail();
    expect($vfcCold->originZone->name)->toBe('KCN QUẾ VÕ 1')
        ->and($vfcCold->destinationZone->name)->toBe('KCN THĂNG LONG')
        ->and($vfcCold->priceFor(1.25))->toBe(727_500.0);

    // Khu vực chỉ có trong bảng giá vẫn được tạo để gán địa điểm sau
    expect(BillingZone::where('name', 'KCN YÊN BÌNH')->firstOrFail()->destinationRates()->exists())->toBeTrue();

    $vfcNormal = FreightRate::where('customer_id', $this->vfc->id)->where('rate_type', '')->firstOrFail();
    expect($vfcNormal->prices)->toEqual(['2' => 741_280.79, '7' => 1_563_851.46]);

    $waiting = WaitingFeeRate::where('customer_id', $this->safo->id)->firstOrFail();
    expect($waiting->tiers)->toEqual([
        ['tonnage' => 1.25, 'fee' => 50_000, 'free_minutes' => 120],
        ['tonnage' => 5, 'fee' => 100_000, 'free_minutes' => 120],
    ])
        ->and($waiting->grace_minutes)->toBe(29)
        ->and($waiting->fee_block_minutes)->toBe(60);

    $engineOn = WaitingFeeRate::where('customer_id', $this->vfc->id)->firstOrFail();
    expect($engineOn->waiting_mode)->toBe(WaitingFeeRate::MODE_ENGINE_ON)
        ->and($engineOn->surcharge_type)->toBe('Hàng lạnh')
        ->and($engineOn->tierFor(1.25))->toEqual(['tonnage' => 1.25, 'fee' => 100_000, 'free_minutes' => 30]);
});

test('dry run reports without writing anything', function () {
    $this->artisan('freight-rates:import', ['--file' => $this->file, '--dry-run' => true])
        ->assertSuccessful();

    expect(FreightRate::count())->toBe(0)
        ->and(WaitingFeeRate::count())->toBe(0)
        ->and(BillingZone::count())->toBe(0)
        ->and($this->fukang->fresh()->billing_zone_id)->toBeNull();
});

test('running the import twice updates instead of duplicating', function () {
    $this->artisan('freight-rates:import', ['--file' => $this->file])->assertSuccessful();
    $counts = [FreightRate::count(), WaitingFeeRate::count(), BillingZone::count()];

    $this->artisan('freight-rates:import', ['--file' => $this->file])->assertSuccessful();

    expect([FreightRate::count(), WaitingFeeRate::count(), BillingZone::count()])->toBe($counts);
});

test('missing file fails clearly', function () {
    $this->artisan('freight-rates:import', ['--file' => '/khong/co/file.xlsx'])
        ->expectsOutputToContain('Không tìm thấy file')
        ->assertFailed();
});
