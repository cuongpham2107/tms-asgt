<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\CargoType;
use App\Enums\Priority;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\OrderCategory;
use App\Models\OrderType;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
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
                                // Select::make('order_type_id')
                                //     ->label('Loại đơn')
                                //     ->options(fn (): array => OrderType::query()
                                //         ->orderBy('sort_order')
                                //         ->pluck('name', 'id')
                                //         ->all())
                                //     ->live()
                                //     ->native(false)
                                //     ->required()
                                //     ->columnSpanFull(),
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
                                    ->reorderableWithDragAndDrop()
                                    ->orderColumn('sequence')
                                    ->defaultItems(0)
                                    ->relationship('deliveryPoints')
                                    ->table(fn (Get $get): array => self::isExternalOrder($get)
                                        ? [
                                            TableColumn::make('Địa điểm')
                                                ->markAsRequired()
                                                ->alignment(Alignment::Start),
                                            TableColumn::make('Khu vực phân loại')
                                                ->width('100px'),
                                            TableColumn::make('Người liên hệ')
                                                ->width('150px'),
                                            TableColumn::make('Số điện thoại')
                                                ->width('150px'),
                                        ]
                                        : [
                                            TableColumn::make('Địa điểm')
                                                ->markAsRequired()
                                                ->alignment(Alignment::Start),
                                            TableColumn::make('Số kiện')
                                                ->width('100px'),
                                            TableColumn::make('Trọng lượng')
                                                ->width('100px'),
                                        ])
                                    ->compact()
                                    ->schema(fn (Get $get): array => self::isExternalOrder($get)
                                        ? [
                                            TextInput::make('address')
                                                ->label('Số nhà, tên đường')
                                                ->placeholder('Ví dụ: 34 Lê Lợi'),
                                            Select::make('location_id')
                                                ->label('Khu vực phân loại')
                                                ->relationship('location', 'name')
                                                ->native(false)
                                                ->required(),
                                            TextInput::make('contact_person')
                                                ->placeholder('Ví dụ: Nguyễn Văn A'),
                                            TextInput::make('contact_phone')
                                                ->placeholder('Ví dụ: 0901234567')
                                                ->tel(),
                                        ]
                                        : [
                                            Select::make('location_id')
                                                ->label('Địa điểm')
                                                ->relationship('location', 'name')
                                                ->native(false)
                                                ->required(),
                                            TextInput::make('total_packages')
                                                ->numeric(),
                                            TextInput::make('total_weight')
                                                ->numeric(),
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

                        Tab::make('Phân xe và lái xe')
                            ->icon('heroicon-o-truck')
                            ->schema([
                                VehiclePicker::make('vehicle_id')
                                    ->label('Phương tiện')
                                    ->cards(fn (Get $get): array => self::resolveVehicleCards(
                                        self::normalizeDecimal($get('total_weight')),
                                        self::isHhhkOrder($get) ? self::normalizeInteger($get('pickup_location_id')) : null,
                                        self::normalizeInteger($get('vehicle_id')),
                                    ))
                                    ->searchPlaceholder('Tìm biển số, loại xe...'),
                                DriverPicker::make('driver_id')
                                    ->label('Lái xe')
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
        $orderTypeId = $get('order_type_id') ?? $get('../../order_type_id');

        if (blank($orderTypeId)) {
            return null;
        }

        return OrderType::query()
            ->whereKey($orderTypeId)
            ->value('code');
    }
}
