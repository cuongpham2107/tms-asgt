<?php

use App\Enums\CargoType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Filament\Pages\TripStatisticsReport;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * File Excel theo mẫu sheet "1.1 Mẫu Upload dữ liệu cân kiện".
 *
 * @param  list<array{0: string, 1: mixed, 2: mixed}>  $rows  [mã đơn, PCS, GW]
 */
function makePackagesUpload(array $rows, string $sheetTitle = '1.1 Mẫu Upload dữ liệu cân kiện'): UploadedFile
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle($sheetTitle);
    $sheet->fromArray(['Loại hình phục vụ', 'Mã đơn hàng', 'Ngày', 'BSX', 'Hành trình', 'PCS', 'GW'], null, 'A1');

    foreach ($rows as $index => [$code, $pcs, $gw]) {
        $sheet->fromArray(['HHHK', $code, '16/09/2026', '20C08846', 'ALSB -> ACSV', $pcs, $gw], null, 'A'.($index + 2), true);
    }

    $path = tempnam(sys_get_temp_dir(), 'pkg').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return UploadedFile::fake()->createWithContent('can-kien.xlsx', file_get_contents($path));
}

beforeEach(function () {
    Gate::before(fn () => true);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $area = Area::create(['code' => 'NBA', 'name' => 'Nội Bài', 'is_active' => true, 'sort_order' => 1]);
    $customer = Customer::create(['code' => 'ALSB', 'name' => 'ALSB', 'is_active' => true]);

    $this->makeOrder = fn (string $code, array $attributes = []): Order => Order::create([
        'order_code' => $code,
        'type' => OrderType::Hhhk,
        'area_id' => $area->id,
        'customer_id' => $customer->id,
        'cargo_type' => CargoType::Gcr,
        'status' => OrderStatus::Completed,
        'created_by' => $this->user->id,
        ...$attributes,
    ]);
});

test('import updates pcs and gw of orders by order code', function () {
    $first = ($this->makeOrder)('ASG-107', ['total_packages' => 1, 'total_weight' => 10]);
    $second = ($this->makeOrder)('ASG-103', ['total_packages' => 5, 'total_weight' => 50]);

    Livewire::test(TripStatisticsReport::class, ['activeTab' => 'HHHK'])
        ->callAction('importPackages', data: [
            'file' => makePackagesUpload([
                ['ASG-107', 12, 345.5],
                // Ô trống thì giữ nguyên giá trị cũ
                ['ASG-103', 8, ''],
                ['ASG-999', 3, 30],
            ]),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($first->fresh()->total_packages)->toBe(12)
        ->and((float) $first->fresh()->total_weight)->toBe(345.5)
        ->and($second->fresh()->total_packages)->toBe(8)
        ->and((float) $second->fresh()->total_weight)->toBe(50.0);
});

test('import reads the first sheet when the template sheet name differs', function () {
    $order = ($this->makeOrder)('ASG-100');

    Livewire::test(TripStatisticsReport::class, ['activeTab' => 'HHHK'])
        ->callAction('importPackages', data: ['file' => makePackagesUpload([['ASG-100', 4, '1,5']], 'Sheet1')])
        ->assertHasNoActionErrors();

    expect($order->fresh()->total_packages)->toBe(4)
        ->and((float) $order->fresh()->total_weight)->toBe(1.5);
});

test('import action is only shown on the HHHK tab', function () {
    Livewire::test(TripStatisticsReport::class, ['activeTab' => 'HHHK'])->assertActionVisible('importPackages');
    Livewire::test(TripStatisticsReport::class, ['activeTab' => 'external'])->assertActionHidden('importPackages');
});
