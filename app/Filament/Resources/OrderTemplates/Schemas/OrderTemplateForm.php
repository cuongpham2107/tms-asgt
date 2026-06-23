<?php

namespace App\Filament\Resources\OrderTemplates\Schemas;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class OrderTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Tên mẫu')
                    ->prefixIcon(Heroicon::OutlinedPencilSquare)
                    ->required(),
                Select::make('order_data.type')
                    ->label('Loại đơn')
                    ->options([
                        'HHHK' => 'Hàng không (HHHK)',
                        'external' => 'Hàng không (HN)',
                    ])
                    ->default('HHHK')
                    ->live()
                    ->required(),
                Section::make('Thông tin chung')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Select::make('order_data.area_id')
                            ->label('Khu vực')
                            ->options(fn (): array => Area::query()
                                ->pluck('code', 'id')
                                ->toArray())
                            ->searchable()
                            ->native(false)
                            ->required(),
                        Select::make('order_data.customer_id')
                            ->label('Khách hàng')
                            ->options(fn (): array => Customer::query()
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->native(false)
                            ->required(),
                    ]),
                Section::make('Điểm nhận hàng')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Select::make('order_data.pickup_location_id')
                            ->label('Điểm nhận hàng')
                            ->options(fn (): array => Location::query()
                                ->pluck('name', 'id')
                                ->toArray())
                            ->searchable()
                            ->native(false)
                            ->visible(fn (Get $get) => $get('order_data.type') === 'HHHK')
                            ->columnSpanFull(),
                        Grid::make(2)
                            ->visible(fn (Get $get) => $get('order_data.type') === 'external')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('order_data.pickup_address_detail')
                                    ->label('Số nhà, tên đường')
                                    ->placeholder('Ví dụ: 12 Nguyễn Huệ'),
                                Select::make('order_data.pickup_province_code')
                                    ->label('Tỉnh / Thành phố')
                                    ->options(fn (): array => self::getProvinceOptions())
                                    ->searchable()
                                    ->native(false)
                                    ->live()
                                    ->preload(),
                                Select::make('order_data.pickup_ward_code')
                                    ->label('Phường / Xã')
                                    ->options(fn (Get $get): array => self::getWardOptions($get('order_data.pickup_province_code')))
                                    ->searchable()
                                    ->native(false)
                                    ->preload()
                                    ->disabled(fn (Get $get): bool => blank($get('order_data.pickup_province_code'))),
                                TextInput::make('order_data.pickup_contact')
                                    ->label('Người liên hệ')
                                    ->placeholder('Ví dụ: Nguyễn Văn A'),
                                TextInput::make('order_data.pickup_phone')
                                    ->label('Số điện thoại')
                                    ->placeholder('Ví dụ: 0901234567')
                                    ->tel(),
                            ]),
                    ]),
                Section::make('Thông tin hàng hoá')
                    ->visible(fn (Get $get) => $get('order_data.type') === 'external')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('order_data.cargo_name')
                            ->label('Tên hàng hoá')
                            ->placeholder('Ví dụ: Hàng điện tử')
                            ->columnSpan(2),
                        ToggleButtons::make('order_data.cargo_type')
                            ->label('Loại hàng')
                            ->default('GCR')
                            ->options([
                                'GCR' => 'Hàng thường (GCR)',
                                'DGR' => 'Hàng nguy hiểm (DGR)',
                            ])
                            ->colors([
                                'GCR' => 'success',
                                'DGR' => 'danger',
                            ])
                            ->icons([
                                'GCR' => Heroicon::OutlinedCheckCircle,
                                'DGR' => Heroicon::OutlinedExclamationTriangle,
                            ])
                            ->inline()
                            ->columnSpan(1),
                        TextInput::make('order_data.total_packages')
                            ->label('Số kiện')
                            ->numeric()
                            ->placeholder('Ví dụ: 10'),
                        TextInput::make('order_data.total_weight')
                            ->label('Trọng lượng')
                            ->numeric()
                            ->placeholder('Ví dụ: 500'),
                    ]),
                Section::make('Điểm giao hàng')
                    ->columnSpanFull()
                    ->schema([
                        Repeater::make('order_data.deliveryPoints')
                            ->label('Danh sách điểm giao')
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['address'] ?? 'Điểm giao mới')
                            ->reorderableWithDragAndDrop()
                            ->defaultItems(0)
                            ->schema([
                                Grid::make(12)->schema([
                                    Select::make('location_id')
                                        ->label('Điểm giao hàng')
                                        ->options(fn (): array => Location::query()
                                            ->pluck('name', 'id')
                                            ->toArray())
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
                                        ->columnSpan(4),
                                    TextInput::make('address')
                                        ->label('Địa chỉ chi tiết')
                                        ->placeholder('Ví dụ: Cổng số 3, ALS')
                                        ->columnSpan(8),
                                    TextInput::make('contact_person')
                                        ->label('Người nhận')
                                        ->placeholder('Ví dụ: Nguyễn Văn A')
                                        ->columnSpan(4),
                                    TextInput::make('contact_phone')
                                        ->label('Số điện thoại')
                                        ->placeholder('Ví dụ: 0901234567')
                                        ->tel()
                                        ->columnSpan(3),
                                    TextInput::make('total_packages')
                                        ->label('Số kiện')
                                        ->numeric()
                                        ->columnSpan(2),
                                    TextInput::make('total_weight')
                                        ->label('Trọng lượng')
                                        ->numeric()
                                        ->columnSpan(3),
                                ]),
                            ])
                            ->columnSpanFull(),
                    ]),
                Section::make('Thông tin thêm')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        Textarea::make('order_data.notes')
                            ->label('Ghi chú')
                            ->columnSpanFull(),
                    ]),
                TextInput::make('quantity')
                    ->label('Số lượng')
                    ->prefixIcon(Heroicon::OutlinedCube)
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('cron_expression')
                    ->label('Cron')
                    ->prefixIcon(Heroicon::OutlinedClock),
                TimePicker::make('daily_run_at')
                    ->label('Chạy lúc')
                    ->prefixIcon(Heroicon::OutlinedClock),
                Toggle::make('is_active')
                    ->label('Kích hoạt')
                    ->required(),
            ]);
    }

    private static function getProvinceOptions(): array
    {
        return Cache::remember('open-api-v1-provinces', now()->addDay(), function (): array {
            try {
                $response = Http::acceptJson()
                    ->timeout(10)
                    ->get('https://provinces.open-api.vn/api/v1/p');

                if (! $response->successful()) {
                    return [];
                }

                return collect($response->json())
                    ->filter(fn ($item): bool => isset($item['code'], $item['name']))
                    ->mapWithKeys(fn ($item): array => [(string) $item['code'] => $item['name']])
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    private static function getWardOptions(int|string|null $provinceCode): array
    {
        if (blank($provinceCode)) {
            return [];
        }

        return Cache::remember("open-api-v2-wards-{$provinceCode}", now()->addDay(), function () use ($provinceCode): array {
            try {
                $response = Http::acceptJson()
                    ->timeout(10)
                    ->get("https://provinces.open-api.vn/api/v2/w/?province={$provinceCode}");

                if (! $response->successful()) {
                    return [];
                }

                return collect($response->json())
                    ->filter(fn ($item): bool => isset($item['code'], $item['name']))
                    ->mapWithKeys(fn ($item): array => [(string) $item['code'] => $item['name']])
                    ->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }
}
