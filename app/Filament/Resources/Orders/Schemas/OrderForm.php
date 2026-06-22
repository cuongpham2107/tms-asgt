<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\Priority;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Customers\Schemas\CustomerForm;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Location;
use App\Models\OrderDeliveryPoint;
use App\Models\Vehicle;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

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
                            ->columns(2)
                            ->schema([
                                ToggleButtons::make('area_id')
                                    ->label('Khu vực')
                                    ->required()
                                    ->options(fn (Get $get): array => Area::query()
                                        ->when($get('type'), fn ($q, $type) => $q->where('type', $type))
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
                                    ->options(fn (Get $get): array => Customer::query()
                                        ->when($get('area_id'), function ($query, $areaId) {
                                            $query->whereHas('locations', fn ($q) => $q->where('area_id', $areaId));
                                        })
                                        ->get(['id', 'code', 'name'])
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
                                    ->afterStateUpdated(function ($state, Set $set): void {
                                        if (blank($state)) {
                                            return;
                                        }

                                        $customer = Customer::query()->find($state);
                                        if ($customer !== null) {
                                            $firstLocation = $customer->locations()->first();
                                            if ($firstLocation !== null) {
                                                $set('pickup_location_id', $firstLocation->id);
                                            }
                                        }
                                    })
                                    ->createOptionForm(fn (Schema $schema): array => CustomerForm::configure($schema)->getComponents()),

                                Select::make('priority')
                                    ->label('Mức ưu tiên')
                                    ->options(Priority::class)
                                    ->default(Priority::Medium->value)
                                    ->native(false)
                                    ->required()
                                    ->columnSpanFull(),

                                TextInput::make('cargo_name')
                                    ->label('Tên hàng hoá')
                                    ->placeholder('Ví dụ: Hàng gia dụng')
                                    ->required()
                                    ->columnSpan(fn (Get $get): int => self::isExternalOrder($get) ? 1 : 2),

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
                                    ->columnSpan(1),

                                Select::make('pickup_location_id')
                                    ->live(onBlur: true)
                                    ->label('Điểm nhận hàng')
                                    ->relationship(
                                        name: 'pickupLocation',
                                        titleAttribute: 'name',
                                        modifyQueryUsing: fn (Builder $query, Get $get) => $query
                                            ->when($get('area_id'), fn ($q, $areaId) => $q->where('area_id', $areaId))
                                    )
                                    ->native(false)
                                    ->required(fn (Get $get): bool => self::isHhhkOrder($get))
                                    ->createOptionForm(fn (Schema $schema): array => LocationForm::configure($schema)->getComponents())
                                    ->columnSpan(fn (Get $get): int => self::isExternalOrder($get) ? 2 : 2),

                                TextInput::make('pickup_contact')
                                    ->label('Người liên hệ nhận')
                                    ->placeholder('Người nhận hàng')
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpan(1),

                                TextInput::make('pickup_phone')
                                    ->label('SĐT liên hệ nhận')
                                    ->placeholder('Số điện thoại')
                                    ->tel()
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpan(1),

                                TextInput::make('pickup_address')
                                    ->label('Địa chỉ chi tiết nhận hàng (nếu có)')
                                    ->placeholder('Số nhà, tên đường...')
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpanFull(),

                                Repeater::make('deliveryPoints')
                                    ->label(fn (Get $get): string => self::isExternalOrder($get) ? 'Địa chỉ giao hàng' : 'Điểm đến')
                                    ->helperText(function (Get $get): string {
                                        $area = Area::query()->find($get('area_id'));

                                        if ($area !== null) {
                                            return 'Chưa có điểm đến phụ. Mặc định đến: '.$area->code;
                                        }

                                        return 'Thêm một hoặc nhiều điểm đến cho đơn hàng';
                                    })
                                    ->collapsible()
                                    ->itemLabel(function (array $state): ?string {
                                        $parts = [];

                                        if (isset($state['location_id']) && $location = Location::query()->find($state['location_id'])) {
                                            $parts[] = $location->name;
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
                                        Grid::make(12)
                                            ->schema([
                                                Select::make('location_id')
                                                    ->label('Điểm giao hàng')
                                                    ->relationship(
                                                        name: 'location',
                                                        titleAttribute: 'name',
                                                        modifyQueryUsing: fn (Builder $query, Get $get) => $query
                                                            ->when($get('../../area_id'), fn ($q, $areaId) => $q->where('area_id', $areaId))
                                                    )
                                                    ->prefixIcon(Heroicon::OutlinedMapPin)
                                                    ->native(false)
                                                    ->required()
                                                    ->columnSpan(4),
                                                TextInput::make('address')
                                                    ->label('Số nhà, tên đường giao')
                                                    ->prefixIcon(Heroicon::OutlinedMap)
                                                    ->placeholder('Ví dụ: 34 Lê Lợi')
                                                    ->columnSpan(8),
                                                TextInput::make('contact_person')
                                                    ->label('Người nhận')
                                                    ->prefixIcon(Heroicon::OutlinedUser)
                                                    ->placeholder('Ví dụ: Nguyễn Văn A')
                                                    ->columnSpan(4),
                                                TextInput::make('contact_phone')
                                                    ->label('Số điện thoại nhận')
                                                    ->prefixIcon(Heroicon::OutlinedPhone)
                                                    ->placeholder('Ví dụ: 0901234567')
                                                    ->tel()
                                                    ->columnSpan(3),
                                                TextInput::make('total_packages')
                                                    ->label('Số kiện')
                                                    ->prefixIcon(Heroicon::OutlinedSquares2x2)
                                                    ->numeric()
                                                    ->columnSpan(2),
                                                TextInput::make('total_weight')
                                                    ->label('Trọng lượng (tấn)')
                                                    ->prefixIcon(Heroicon::OutlinedScale)
                                                    ->numeric()
                                                    ->columnSpan(3),
                                            ]),
                                    ])
                                    ->columnSpanFull(),

                                DateTimePicker::make('planned_loading_at')
                                    ->label('Thời gian dự kiến đóng hàng')
                                    ->seconds(false)
                                    ->native(false)
                                    ->required()
                                    ->columnSpanFull(),
                                TextInput::make('total_packages')
                                    ->label('Số kiện')
                                    ->numeric(),
                                TextInput::make('total_weight')
                                    ->label('Trọng lượng (tấn)')
                                    ->live(onBlur: true)
                                    ->numeric(),
                                Textarea::make('notes')
                                    ->label('Ghi chú')
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Hành trình')
                            ->icon('heroicon-o-map')
                            ->schema([
                                Repeater::make('tripCheckpoints')
                                    ->label('Checkpoint hành trình')
                                    ->relationship()
                                    ->reorderableWithDragAndDrop()
                                    ->defaultItems(1)
                                    ->schema([
                                        Grid::make(12)
                                            ->schema([
                                                Select::make('checkpoint_type')
                                                    ->label('Loại')
                                                    ->options(CheckpointType::class)
                                                    ->required()
                                                    ->native(false)
                                                    ->columnSpan(3),
                                                DateTimePicker::make('occurred_at')
                                                    ->label('Thời điểm')
                                                    ->seconds(false)
                                                    ->native(false)
                                                    ->required()
                                                    ->columnSpan(3),
                                                TextInput::make('km_reading')
                                                    ->label('Số km')
                                                    ->numeric()
                                                    ->columnSpan(2),
                                                Select::make('delivery_point_id')
                                                    ->label('Điểm giao hàng')
                                                    ->options(function (Get $get): array {
                                                        $orderId = $get('../../id');
                                                        if (! $orderId) {
                                                            return [];
                                                        }

                                                        return OrderDeliveryPoint::query()
                                                            ->where('order_id', $orderId)
                                                            ->with('location')
                                                            ->get()
                                                            ->mapWithKeys(fn ($dp) => [
                                                                $dp->id => $dp->address ?: ($dp->location?->name ?? 'Điểm giao '.$dp->sequence),
                                                            ])
                                                            ->toArray();
                                                    })
                                                    ->placeholder('Chọn điểm giao')
                                                    ->native(false)
                                                    ->columnSpan(4),
                                                Textarea::make('voice_note')
                                                    ->label('Ghi chú')
                                                    ->columnSpanFull(),
                                            ]),
                                    ])
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Phân xe')
                            ->icon('heroicon-o-truck')
                            ->schema([
                                VehiclePicker::make('vehicle_id')
                                    ->label('Phương tiện')
                                    ->live()
                                    ->afterStateUpdated(function (Set $set, $state): void {
                                        if ($state) {
                                            $vehicle = Vehicle::query()->find($state);
                                            $set('driver_id', $vehicle?->current_driver_id ?? null);
                                        } else {
                                            $set('driver_id', null);
                                        }
                                    })
                                    ->cards(fn (Get $get): array => self::resolveVehicleCards(
                                        self::normalizeDecimal($get('total_weight')),
                                        self::isHhhkOrder($get) ? self::normalizeInteger($get('pickup_location_id')) : null,
                                        self::normalizeInteger($get('vehicle_id')),
                                    ))
                                    ->searchPlaceholder('Tìm biển số, loại xe...'),

                                DriverPicker::make('driver_id')
                                    ->label('Lái xe')
                                    ->live()
                                    ->cards(fn (): array => self::resolveDriverCards())
                                    ->searchPlaceholder('Tìm tên, email...')
                                    ->required(),

                                Checkbox::make('override_shift_check')
                                    ->label('Bỏ qua kiểm tra ca')
                                    ->helperText('Cho phép gán xe dù xe không có ca đang hoạt động')
                                    ->default(true)
                                    ->live()
                                    ->dehydrated(false)
                                    ->visible(fn (Get $get): bool => filled($get('vehicle_id'))),
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
