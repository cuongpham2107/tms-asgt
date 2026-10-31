<?php

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

/**
 * Tạo file Excel giống mẫu "Bản tổng hợp địa điểm giao nhận hàng": 2 sheet location có cột link Google Maps.
 *
 * @param  array<int, array{0: string, 1: string}>  $hhhk  [mã, link]
 * @param  array<int, array{0: string, 1: string}>  $external  [mã nhà máy, link]
 */
function locationWorkbook(array $hhhk, array $external = []): string
{
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet()->setTitle('DATA location HHHK');
    $sheet->fromArray(['STT', 'Khu vực', 'Địa điểm viết tắt', 'Tên công ty chi tiết', 'Địa chỉ', 'Link Google Map'], null, 'A1');
    foreach ($hhhk as $i => [$code, $link]) {
        $sheet->fromArray([$i + 1, 'NBA', $code, "Công ty {$code}", 'Nội Bài', $link], null, 'A'.($i + 2));
    }

    $other = $book->createSheet()->setTitle('Data locationHàng ngoài');
    $other->fromArray(['STT', 'Khu vực', 'Mã viết tắt nhà máy', 'Địa điểm viết tắt', 'Công ty', 'Địa chỉ', 'Xã/phường', 'Tỉnh', 'Link goole Map'], null, 'A1');
    foreach ($external as $i => [$code, $link]) {
        $other->fromArray([$i + 1, 'BN', $code, 'YÊN PHONG', "Công ty {$code}", 'KCN', 'Yên Trung', 'Bắc Ninh', $link], null, 'A'.($i + 2));
    }

    $path = tempnam(sys_get_temp_dir(), 'loc').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

function shortLinkRedirect(float $lat, float $lng): string
{
    return "https://www.google.com/maps/place/Kho/@{$lat},105.80,17z/data=!4m6!3m5!8m2!3d{$lat}!4d{$lng}!16s";
}

beforeEach(function () {
    $this->acsv = Location::create(['code' => 'ACSV', 'name' => 'ACSV', 'lat' => 21.2188925, 'lng' => 105.8044596, 'is_active' => true]);
    $this->clc = Location::create(['code' => 'CLC', 'name' => 'CLC', 'lat' => 21.1988117, 'lng' => 105.9988539, 'is_active' => true]);

    Http::fake([
        'https://maps.app.goo.gl/acsv' => Http::response('', 302, ['Location' => shortLinkRedirect(21.2128717, 105.8072561)]),
        'https://maps.app.goo.gl/clc' => Http::response('', 302, ['Location' => 'https://www.google.com/maps/search/21.194375,+106.0099275?entry=tts']),
        'https://maps.app.goo.gl/shared' => Http::response('', 302, ['Location' => shortLinkRedirect(21.0, 105.9)]),
    ]);
});

test('dry run shows coordinates read from short Google Maps links without saving', function () {
    $file = locationWorkbook([['ACSV', 'https://maps.app.goo.gl/acsv']], [['CLC', 'https://maps.app.goo.gl/clc']]);

    $this->artisan('locations:import-coordinates', ['file' => $file])
        ->expectsOutputToContain('21.2128717, 105.8072561')
        ->assertSuccessful();

    expect((float) $this->acsv->fresh()->lat)->toBe(21.2188925);
});

test('apply updates every location with the code, from both sheets', function () {
    $acsvDuplicate = Location::create(['code' => 'acsv ', 'name' => 'ACSV mới', 'lat' => 21.0, 'lng' => 105.0, 'is_active' => true]);
    $file = locationWorkbook([['ACSV', 'https://maps.app.goo.gl/acsv']], [['CLC', 'https://maps.app.goo.gl/clc']]);

    $this->artisan('locations:import-coordinates', ['file' => $file, '--apply' => true])->assertSuccessful();

    expect((float) $this->acsv->fresh()->lat)->toBe(21.2128717)
        ->and((float) $this->acsv->fresh()->lng)->toBe(105.8072561)
        ->and((float) $acsvDuplicate->fresh()->lat)->toBe(21.2128717)
        ->and((float) $this->clc->fresh()->lat)->toBe(21.194375)
        ->and((float) $this->clc->fresh()->lng)->toBe(106.0099275);
});

test('reads coordinates written directly in a full Google Maps link without calling Google', function () {
    $file = locationWorkbook([['ACSV', 'https://www.google.com/maps/place/ACSV/@21.21,105.80,17z/data=!3d21.2128717!4d105.8072561']]);

    $this->artisan('locations:import-coordinates', ['file' => $file, '--apply' => true])->assertSuccessful();

    expect((float) $this->acsv->fresh()->lat)->toBe(21.2128717);
    Http::assertNothingSent();
});

test('skips a link shared by different codes, codes listed in --skip and unknown codes', function () {
    Location::create(['code' => 'HADANBI', 'name' => 'Hadanbi', 'lat' => 21.5, 'lng' => 105.5, 'is_active' => true]);
    Location::create(['code' => 'DIEMTHUY', 'name' => 'Điềm Thụy', 'lat' => 21.6, 'lng' => 105.6, 'is_active' => true]);
    $file = locationWorkbook(
        [['ACSV', 'https://maps.app.goo.gl/acsv']],
        [['HADANBI', 'https://maps.app.goo.gl/shared'], ['DIEMTHUY', 'https://maps.app.goo.gl/shared'], ['KHONGCO', 'https://www.google.com/maps/place/X/data=!3d21.3!4d105.7'], ['CLC', 'https://maps.app.goo.gl/clc']],
    );

    $this->artisan('locations:import-coordinates', ['file' => $file, '--apply' => true, '--skip' => 'ACSV'])
        ->expectsOutputToContain('HADANBI')
        ->expectsOutputToContain('KHONGCO')
        ->assertSuccessful();

    expect((float) Location::where('code', 'HADANBI')->value('lat'))->toBe(21.5)
        ->and((float) Location::where('code', 'DIEMTHUY')->value('lat'))->toBe(21.6)
        ->and((float) $this->acsv->fresh()->lat)->toBe(21.2188925)
        ->and((float) $this->clc->fresh()->lat)->toBe(21.194375);
});

test('skips a code that appears with two different links', function () {
    $file = locationWorkbook([['ACSV', 'https://maps.app.goo.gl/acsv']], [['ACSV', 'https://maps.app.goo.gl/clc']]);

    $this->artisan('locations:import-coordinates', ['file' => $file, '--apply' => true])->assertSuccessful();

    expect((float) $this->acsv->fresh()->lat)->toBe(21.2188925);
});

test('a link Google cannot resolve is reported and skipped', function () {
    Http::fake(['https://maps.app.goo.gl/broken' => Http::response('', 404)]);
    $file = locationWorkbook([['ACSV', 'https://maps.app.goo.gl/broken']]);

    $this->artisan('locations:import-coordinates', ['file' => $file, '--apply' => true])
        ->expectsOutputToContain('ACSV')
        ->assertSuccessful();

    expect((float) $this->acsv->fresh()->lat)->toBe(21.2188925);
});

test('without a file argument it reads the location sheets stored in data/', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $this->artisan('locations:import-coordinates')
        ->expectsOutputToContain('ACSV')
        ->assertSuccessful();

    expect((float) $this->acsv->fresh()->lat)->toBe(21.2188925);
});

test('a missing file fails with a clear message', function () {
    $this->artisan('locations:import-coordinates', ['file' => '/khong/co/file.xlsx'])
        ->expectsOutputToContain('Không tìm thấy file')
        ->assertFailed();
});

test('apply only changes lat and lng: no location is deleted, created or otherwise modified', function () {
    $this->acsv->update(['address' => 'Nhà ga hàng hoá Nội Bài', 'is_active' => true]);
    $before = Location::orderBy('id')->get()->map->only(['id', 'code', 'name', 'address', 'is_active', 'area_id']);
    $file = locationWorkbook([['ACSV', 'https://maps.app.goo.gl/acsv'], ['MOI', 'https://maps.app.goo.gl/clc']]);

    $this->artisan('locations:import-coordinates', ['file' => $file, '--apply' => true])->assertSuccessful();

    expect(Location::orderBy('id')->get()->map->only(['id', 'code', 'name', 'address', 'is_active', 'area_id']))->toEqual($before)
        ->and((float) $this->acsv->fresh()->lat)->toBe(21.2128717);
});
