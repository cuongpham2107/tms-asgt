<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\Priority;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Location;
use App\Models\OrderCategory;
use App\Models\Vehicle;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                                Hidden::make('order_category_id'),
                                Select::make('customer_id')
                                    ->label('Khách hàng')
                                    ->relationship('customer', 'name')
                                    ->native(false)
                                    ->required()
                                    ->columnSpanFull(),
                                Select::make('priority')
                                    ->label('Mức ưu tiên')
                                    ->options(Priority::class)
                                    ->default(Priority::Medium->value)
                                    ->native(false)
                                    ->required()
                                    ->columnSpanFull(),
                                FusedGroup::make([
                                    TextInput::make('cargo_name')
                                        ->placeholder('Tên hàng hoá')
                                        ->columnSpan(2),
                                    TextInput::make('total_packages')
                                        ->placeholder('Tổng kiện')
                                        ->numeric()
                                        ->columnSpan(1),
                                    TextInput::make('total_weight')
                                        ->placeholder('Trọng lượng (kg)')
                                        ->numeric()
                                        ->live(onBlur: true)
                                        ->columnSpan(1),
                                ])
                                    ->label('Thông tin hàng hoá')
                                    ->columns(4)
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpanFull(),
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
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpanFull(),
                                FusedGroup::make([
                                    TextInput::make('pickup_address')
                                        ->label('Số nhà, tên đường')
                                        ->placeholder('Ví dụ: 12 Nguyễn Huệ')
                                        ->required(fn (Get $get): bool => self::isExternalOrder($get)),
                                    TextInput::make('pickup_contact')
                                        ->hiddenLabel()
                                        ->placeholder('Người liên hệ nhận hàng'),
                                    TextInput::make('pickup_phone')
                                        ->hiddenLabel()
                                        ->placeholder('Số điện thoại nhận hàng')
                                        ->tel(),
                                ])
                                    ->label('Địa chỉ nhận hàng')
                                    ->columns(3)
                                    ->visible(fn (Get $get): bool => self::isExternalOrder($get))
                                    ->columnSpanFull(),
                                Select::make('pickup_location_id')
                                    ->live(onBlur: true)
                                    ->label('Điểm đi')
                                    ->relationship('pickupLocation', 'name')
                                    ->native(false)
                                    ->required(fn (Get $get): bool => self::isHhhkOrder($get))
                                    ->visible(fn (Get $get): bool => self::isHhhkOrder($get))
                                    ->columnSpanFull(),
                                Repeater::make('deliveryPoints')
                                    ->label(fn (Get $get): string => self::isExternalOrder($get) ? 'Địa chỉ giao hàng' : 'Điểm đến')
                                    ->helperText(function (Get $get): string {
                                        $orderCategory = OrderCategory::query()->find($get('order_category_id'));

                                        if ($orderCategory !== null) {
                                            return 'Chưa có điểm đến phụ. Mặc định đến: '.$orderCategory->code;
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

                                        if (! empty($state['total_packages'])) {
                                            $parts[] = $state['total_packages'].' kiện';
                                        }

                                        if (! empty($state['total_weight'])) {
                                            $parts[] = $state['total_weight'].' tấn';
                                        }

                                        return count($parts) > 0 ? implode(' - ', $parts) : 'Điểm giao hàng mới';
                                    })
                                    ->reorderableWithDragAndDrop()
                                    ->orderColumn('sequence')
                                    ->defaultItems(0)
                                    ->relationship('deliveryPoints')
                                    ->schema([
                                        Grid::make(12)
                                            ->schema([
                                                Select::make('location_id')
                                                    ->label('Điểm giao hàng')
                                                    ->relationship('location', 'name')
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
                                    ->addable(false)
                                    ->deletable(false)
                                    ->reorderable(false)
                                    ->schema([
                                        Grid::make(12)
                                            ->schema([
                                                Select::make('checkpoint_type')
                                                    ->label('Loại')
                                                    ->options(CheckpointType::class)
                                                    ->disabled()
                                                    ->native(false)
                                                    ->columnSpan(3),
                                                DateTimePicker::make('occurred_at')
                                                    ->label('Thời điểm')
                                                    ->disabled()
                                                    ->seconds(false)
                                                    ->native(false)
                                                    ->columnSpan(3),
                                                TextInput::make('km_reading')
                                                    ->label('Số km')
                                                    ->disabled()
                                                    ->numeric()
                                                    ->columnSpan(2),
                                                TextInput::make('gps_lat')
                                                    ->label('Vĩ độ')
                                                    ->disabled()
                                                    ->columnSpan(2),
                                                TextInput::make('gps_lng')
                                                    ->label('Kinh độ')
                                                    ->disabled()
                                                    ->columnSpan(2),
                                                Textarea::make('voice_note')
                                                    ->label('Ghi chú')
                                                    ->disabled()
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
                                            $vehicle = Vehicle::withCount(['driverShifts as active_shift_count' => fn ($q) => $q->whereNull('end_time')])->find($state);
                                            $set('driver_id', $vehicle?->current_driver_id ?? null);

                                            if ($vehicle && $vehicle->active_shift_count === 0) {
                                                Notification::make()
                                                    ->warning()
                                                    ->title('Xe chưa có ca làm việc')
                                                    ->body('Xe này hiện không có ca nào đang hoạt động. Tài xế sẽ không thể bắt đầu ca mới.')
                                                    ->send();
                                            }
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
                                Hidden::make('driver_id'),
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
