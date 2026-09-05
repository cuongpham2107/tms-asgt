<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\CargoType;
use App\Enums\OrderStatus;
use App\Enums\Priority;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Area;
use App\Models\Customer;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OrderForm extends CreatesOrderTransportCards
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('OrderTabs')
                    ->tabs([
                        Tab::make('Thông tin đơn hàng')
                            ->icon('heroicon-o-information-circle')
                            ->columns(['default' => 1, 'sm' => 2, 'md' => 4])
                            ->schema([
                                ToggleButtons::make('area_id')
                                    ->label('Khu vực')
                                    ->required()
                                    ->options(fn (): array => Area::query()
                                        ->where('is_active', true)
                                        ->orderBy('sort_order', 'asc')
                                        ->pluck('code', 'id')
                                        ->toArray()
                                    )
                                    ->inline()
                                    ->live()
                                    ->columnSpanFull(),

                                Select::make('customer_id')
                                    ->label('Khách hàng')
                                    ->relationship('customer', 'name')
                                    ->options(fn (): array => Customer::query()
                                        ->select(['id', 'code', 'name'])
                                        ->where('is_active', true)
                                        ->orderBy('code', 'asc')
                                        ->get()
                                        ->mapWithKeys(fn (Customer $customer): array => [
                                            $customer->id => "{$customer->code} - {$customer->name}",
                                        ])
                                        ->toArray()
                                    )
                                    ->native(false)
                                    ->required()
                                    ->searchable()
                                    ->columnSpanFull()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                                        if (blank($state)) {
                                            $set('pickup_location_id', null);

                                            return;
                                        }

                                        $customer = Customer::query()->with('location')->find($state);
                                        if (! $customer) {
                                            $set('pickup_location_id', null);

                                            return;
                                        }

                                        $currentAreaId = $get('area_id');
                                        $firstLocation = null;

                                        // 1. Ưu tiên địa điểm mặc định trực tiếp của khách hàng (ví dụ ALSB -> ALSB)
                                        if ($customer->location && $customer->location->is_active) {
                                            $firstLocation = $customer->location;
                                        }

                                        // 2. Nếu khách hàng không có địa điểm trực tiếp, tìm trong bảng pivot customer_location
                                        if (! $firstLocation) {
                                            $query = DB::table('customer_location')
                                                ->join('locations', 'customer_location.location_id', '=', 'locations.id')
                                                ->where('customer_location.customer_id', $state)
                                                ->where('locations.is_active', true);

                                            if (filled($currentAreaId)) {
                                                $firstLocation = (clone $query)
                                                    ->where('locations.area_id', $currentAreaId)
                                                    ->select(['locations.id', 'locations.area_id'])
                                                    ->first();
                                            }

                                            $firstLocation ??= $query->select(['locations.id', 'locations.area_id'])->first();
                                        }

                                        if ($firstLocation) {
                                            $set('pickup_location_id', $firstLocation->id);
                                        } else {
                                            $set('pickup_location_id', null);
                                        }
                                    })
                                    ->createOptionForm(fn (Schema $schema): array => CustomerForm::configure($schema)->getComponents()),

                                // Select::make('priority')
                                //     ->label('Mức ưu tiên')
                                //     ->options(Priority::class)
                                //     ->default(Priority::Medium->value)
                                //     ->native(false)
                                //     ->required()
                                //     ->columnSpanFull(),

                                TextInput::make('cargo_name')
                                    ->label('Tên hàng hoá')
                                    ->placeholder('Ví dụ: Hàng gia dụng')
                                    ->columnSpan(['default' => 1, 'sm' => fn (Get $get): int => self::isExternalOrder($get) ? 1 : 2, 'md' => fn (Get $get): int => self::isExternalOrder($get) ? 2 : 4]),

                                TextInput::make('chargeable_weight')
                                    ->label('Tải trọng tính cước')
                                    ->suffix('tấn')
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->required()
                                    ->datalist([1.25, 1.5, 2.5, 3.5, 5, 7, 8, 10, 14])
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1]),

                                ToggleButtons::make('cargo_type')
                                    ->label('Loại hàng')
                                    ->default(CargoType::Gcr->value)
                                    ->options(CargoType::class)
                                    ->colors([
                                        CargoType::Gcr->value => 'success',
                                        CargoType::Dangerous->value => 'danger',
                                    ])
                                    ->icons([
                                        CargoType::Gcr->value => Heroicon::OutlinedCheckCircle,
                                        CargoType::Dangerous->value => Heroicon::OutlinedExclamationTriangle,
                                    ])
                                    ->inline()
                                    ->required()
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1]),

                                Select::make('pickup_location_id')
                                    ->label('Điểm nhận hàng')
                                    ->relationship(
                                        name: 'pickupLocation',
                                        titleAttribute: 'code',
                                        modifyQueryUsing: fn (Builder $query) => $query->where('is_active', true)
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->native(false)
                                    ->required(fn (Get $get): bool => self::isHhhkOrder($get))
                                    ->createOptionForm(fn (Schema $schema, Get $get): array => LocationForm::configure($schema, $get('area_id'))->getComponents())
                                    ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 2]),

                                DateTimePicker::make('planned_loading_at')
                                    ->label('Thời gian dự kiến đóng hàng')
                                    ->seconds(false)
                                    ->native(true)
                                    ->prefixIcon(Heroicon::OutlinedCalendarDays)
                                    ->required()
                                    ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1]),

                                Toggle::make('is_return_trip')
                                    ->label('Chuyến quay đầu')
                                    ->helperText('Đánh dấu đơn hàng là chuyến quay đầu')
                                    ->default(false)
                                    ->inline(false)
                                    ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1]),

                                TextInput::make('pickup_contact')
                                    ->label('Người liên hệ nhận')
                                    ->placeholder('Người nhận hàng')
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2]),

                                TextInput::make('pickup_phone')
                                    ->label('SĐT liên hệ nhận')
                                    ->placeholder('Số điện thoại')
                                    ->tel()
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2]),

                                TextInput::make('pickup_address')
                                    ->label('Địa chỉ chi tiết nhận hàng (nếu có)')
                                    ->placeholder('Số nhà, tên đường...')
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpanFull(),

                                Repeater::make('deliveryPoints')
                                    ->label(fn (Get $get): string => self::isExternalOrder($get) ? 'Địa chỉ giao hàng' : 'Điểm đến')
                                    ->helperText(function (Get $get): string {
                                        $areaId = $get('area_id');
                                        if ($areaId) {
                                            $areaCode = Area::query()->where('id', $areaId)->value('code');
                                            if ($areaCode !== null) {
                                                return 'Chưa có điểm đến phụ. Mặc định đến: '.$areaCode;
                                            }
                                        }

                                        return 'Thêm một hoặc nhiều điểm đến cho đơn hàng';
                                    })
                                    ->collapsible()
                                    ->itemLabel(function (array $state): ?string {
                                        $parts = [];

                                        if (isset($state['location_id']) && $name = self::resolveLocationName($state['location_id'])) {
                                            $parts[] = $name;
                                        }

                                        if (! empty($state['address'])) {
                                            $parts[] = $state['address'];
                                        }

                                        return count($parts) > 0 ? implode(' - ', $parts) : 'Điểm giao hàng mới';
                                    })
                                    ->reorderableWithDragAndDrop()
                                    ->orderColumn('sequence')
                                    ->relationship('deliveryPoints')
                                    ->schema([
                                        Grid::make(['default' => 1, 'sm' => 6, 'md' => 12])
                                            ->schema([
                                                Select::make('location_id')
                                                    ->label('Điểm giao hàng')
                                                    ->relationship(
                                                        name: 'location',
                                                        titleAttribute: 'code',
                                                        modifyQueryUsing: fn (Builder $query, Get $get) => $query
                                                            ->where(function (Builder $q) use ($get) {
                                                                $q->where('is_active', true)
                                                                    ->when($get('../../area_id'), fn ($q, $areaId) => $q->where('area_id', $areaId));
                                                            })
                                                            ->when($get('location_id'), fn ($q, $id) => $q->orWhere('id', $id))
                                                    )
                                                    ->searchable()
                                                    ->preload()
                                                    ->prefixIcon(Heroicon::OutlinedMapPin)
                                                    ->native(false)
                                                    ->required()
                                                    ->createOptionForm(fn (Schema $schema, Get $get): array => LocationForm::configure($schema, $get('../../area_id') ?? $get('area_id'))->getComponents())
                                                    ->columnSpan(['default' => 1, 'sm' => 6, 'md' => 4]),
                                                TextInput::make('address')
                                                    ->label('Số nhà, tên đường giao')
                                                    ->prefixIcon(Heroicon::OutlinedMap)
                                                    ->placeholder('Ví dụ: 34 Lê Lợi')
                                                    ->columnSpan(['default' => 1, 'sm' => 6, 'md' => 8]),
                                                // TextInput::make('contact_person')
                                                //     ->label('Người nhận')
                                                //     ->prefixIcon(Heroicon::OutlinedUser)
                                                //     ->placeholder('Ví dụ: Nguyễn Văn A')
                                                //     ->columnSpan(['default' => 1, 'sm' => 3, 'md' => 4]),
                                                // TextInput::make('contact_phone')
                                                //     ->label('Số điện thoại nhận')
                                                //     ->prefixIcon(Heroicon::OutlinedPhone)
                                                //     ->placeholder('Ví dụ: 0901234567')
                                                //     ->tel()
                                                //     ->columnSpan(['default' => 1, 'sm' => 3, 'md' => 3]),
                                                // TextInput::make('total_packages')
                                                //     ->label('Số kiện')
                                                //     ->prefixIcon(Heroicon::OutlinedSquares2x2)
                                                //     ->mask(RawJs::make('$money($input)'))
                                                //     ->stripCharacters(',')
                                                //     ->numeric()
                                                //     ->columnSpan(['default' => 1, 'sm' => 3, 'md' => 2]),
                                                // TextInput::make('total_weight')
                                                //     ->label('Trọng lượng (tấn)')
                                                //     ->prefixIcon(Heroicon::OutlinedScale)
                                                //     ->mask(RawJs::make('$money($input)'))
                                                //     ->stripCharacters(',')
                                                //     ->numeric()
                                                //     ->columnSpan(['default' => 1, 'sm' => 3, 'md' => 3]),
                                            ]),
                                    ])
                                    ->columnSpanFull(),

                                TextInput::make('total_packages')
                                    ->label('Số kiện')
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2]),
                                TextInput::make('total_weight')
                                    ->label('Trọng lượng (tấn)')
                                    ->live(onBlur: true)
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 2]),
                                Textarea::make('notes')
                                    ->label('Ghi chú')
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Phân xe')
                            ->icon('heroicon-o-truck')
                            ->hidden(fn (?Model $record): bool => $record?->trip_id !== null || $record?->status === OrderStatus::Draft)
                            ->columns(['default' => 1, 'md' => 2])
                            ->schema([
                                VehiclePicker::make('vehicle_id')
                                    ->label('Phương tiện')
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set, $state) => self::handleVehicleStateUpdated($set, $state))
                                    ->cards(fn (Get $get): array => self::resolveVehicleCards(
                                        self::normalizeDecimal($get('total_weight')),
                                        self::isHhhkOrder($get) ? self::normalizeInteger($get('pickup_location_id')) : null,
                                        self::normalizeInteger($get('vehicle_id')),
                                    ))
                                    ->searchPlaceholder('Tìm biển số, loại xe...'),

                                DriverPicker::make('driver_id')
                                    ->label('Lái xe')
                                    ->live()
                                    ->afterStateUpdated(fn (Set $set, $state) => self::handleDriverStateUpdated($set, $state))
                                    ->cards(fn (): array => self::resolveDriverCards())
                                    ->searchPlaceholder('Tìm tên, email...'),

                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function isHhhkOrder(Get $get): bool
    {
        return self::getSelectedOrderTypeCode($get) === 'HHHK';
    }

    private static function isExternalOrder(Get $get): bool
    {
        return self::getSelectedOrderTypeCode($get) === 'external';
    }

    private static function getSelectedOrderTypeCode(Get $get): ?string
    {
        return $get('type') ?? $get('../../type');
    }
}
