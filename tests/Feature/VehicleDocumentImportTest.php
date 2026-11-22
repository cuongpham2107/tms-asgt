<?php

use App\Enums\VehicleDocumentStatus;
use App\Enums\VehicleDocumentType;
use App\Filament\Resources\VehicleDocuments\Pages\ListVehicleDocuments;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Services\Import\VehicleDocumentImporter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * Điền các dòng vào file mẫu thật rồi lưu ra file tạm.
 *
 * @param  list<list<mixed>>  $rows
 */
function documentImportFile(array $rows): string
{
    $spreadsheet = app(VehicleDocumentImporter::class)->template();
    $spreadsheet->getSheetByName(VehicleDocumentImporter::SHEET_NAME)->fromArray($rows, null, 'A2', true);

    $path = tempnam(sys_get_temp_dir(), 'docs').'.xlsx';
    (new Xlsx($spreadsheet))->save($path);

    return $path;
}

beforeEach(function (): void {
    Gate::before(fn () => true);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->travelTo(Carbon::parse('2026-11-22 10:00'));

    $this->vehicle = Vehicle::factory()->create(['plate_number' => '29C-123.45']);
    $this->otherVehicle = Vehicle::factory()->create(['plate_number' => '20C13238']);
});

it('imports valid rows, reports invalid ones and sets status from the expiry date', function (): void {
    $path = documentImportFile([
        ['29c12345', 'Đăng kiểm', 'KD-001', 'Trung tâm 29-01V', '15/06/2026', '10/12/2026', 560000, '15/06/2026', 'Đăng kiểm 6 tháng'],
        ['20C-132.38', 'Đăng ký xe', 'DK-777', 'Phòng CSGT Hà Nội', ExcelDate::PHPToExcel(new DateTime('2020-01-05')), ExcelDate::PHPToExcel(new DateTime('2030-01-05')), null, null, null],
        ['20C-132.38', 'đăng kiểm', 'KD-OLD', 'Trung tâm 20-01D', '01/01/2026', '30/06/2026', null, null, null],
        ['99X-999.99', 'Đăng kiểm', 'KD-X', 'Nơi cấp', '01/01/2026', '01/06/2027', null, null, null],
        ['29C-123.45', 'Bảo hiểm', 'BH-1', 'Bảo Việt', '01/01/2026', '01/01/2027', null, null, null],
        ['29C-123.45', 'Đăng kiểm', 'KD-002', 'Trung tâm 29-01V', '31/02/2026', '01/06/2027', null, null, null],
    ]);

    $result = app(VehicleDocumentImporter::class)->import($path, $this->user->id);

    expect($result['created'])->toBe(3)
        ->and($result['updated'])->toBe(0)
        ->and($result['errors'])->toHaveCount(3)
        ->and($result['errors'][0])->toContain('Dòng 5')->toContain('không tìm thấy xe 99X-999.99')
        ->and($result['errors'][1])->toContain('Dòng 6')->toContain('Bảo hiểm')
        ->and($result['errors'][2])->toContain('Dòng 7')->toContain('ngày');

    $inspection = VehicleDocument::firstWhere('certificate_number', 'KD-001');
    expect($inspection->vehicle_id)->toBe($this->vehicle->id)
        ->and($inspection->doc_type)->toBe(VehicleDocumentType::Inspection)
        ->and($inspection->expiry_date->toDateString())->toBe('2026-12-10')
        ->and($inspection->renewal_cost)->toBe(560000)
        ->and($inspection->status)->toBe(VehicleDocumentStatus::ExpiringSoon)
        ->and($inspection->created_by)->toBe($this->user->id);

    expect(VehicleDocument::firstWhere('certificate_number', 'DK-777'))
        ->vehicle_id->toBe($this->otherVehicle->id)
        ->status->toBe(VehicleDocumentStatus::Active)
        ->and(VehicleDocument::firstWhere('certificate_number', 'KD-OLD')->status)->toBe(VehicleDocumentStatus::Expired);

    unlink($path);
});

it('updates an existing document with the same vehicle, type and certificate number', function (): void {
    $first = documentImportFile([['29C-123.45', 'Đăng kiểm', 'KD-001', 'Trung tâm 29-01V', '15/06/2026', '10/12/2026', null, null, null]]);
    $second = documentImportFile([['29C-123.45', 'Đăng kiểm', 'KD-001', 'Trung tâm 29-01V', '15/06/2026', '15/06/2027', '600000', '20/11/2026', 'Đã gia hạn']]);

    app(VehicleDocumentImporter::class)->import($first, $this->user->id);
    $result = app(VehicleDocumentImporter::class)->import($second, $this->user->id);

    expect($result)->toMatchArray(['created' => 0, 'updated' => 1, 'errors' => []])
        ->and(VehicleDocument::count())->toBe(1)
        ->and(VehicleDocument::first())
        ->expiry_date->toDateString()->toBe('2027-06-15')
        ->status->toBe(VehicleDocumentStatus::Active)
        ->notes->toBe('Đã gia hạn');

    unlink($first);
    unlink($second);
});

it('imports through the list page action and downloads the template', function (): void {
    $path = documentImportFile([['29C-123.45', 'Đăng ký xe', 'DK-100', 'Phòng CSGT', '01/01/2025', '01/01/2035', null, null, null]]);
    $upload = UploadedFile::fake()->createWithContent('giay-to.xlsx', file_get_contents($path));

    Livewire::test(ListVehicleDocuments::class)
        ->callAction('importDocuments', ['file' => $upload])
        ->assertHasNoActionErrors()
        ->assertNotified('Đã tạo 1 và cập nhật 0 giấy tờ xe')
        ->call('downloadTemplate')
        ->assertFileDownloaded('Mau-import-giay-to-xe.xlsx');

    expect(VehicleDocument::firstWhere('certificate_number', 'DK-100')?->vehicle_id)->toBe($this->vehicle->id);

    unlink($path);
});
