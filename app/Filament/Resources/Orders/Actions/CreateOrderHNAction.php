<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\Priority;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Order;
use App\Models\OrderCategory;
use App\Models\OrderType;
use App\Models\Vehicle;
use CodeWithDennis\FilamentAdvancedChoice\Filament\Forms\Components\RadioCard;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class CreateOrderHNAction extends CreatesOrderTransportCards
{
    public static function make(bool $forceAssignedWhenTransportProvided = true): Action
    {
        return Action::make('create_order_hang_ngoai_action')
            ->label('Tạo đơn hàng ngoài')
            ->size('lg')
            ->icon('heroicon-o-truck')
            ->extraAttributes([
                'class' => 'text-white font-bold [&_.fi-icon]:text-white! bg-[#4CAF50] cursor-pointer hover:bg-[#45a049] transition-colors',
            ])
            ->modal()
            ->modalWidth('5xl')
            ->modalHeading('Tạo đơn hàng ngoài')
            ->modalDescription('Tạo đơn hàng ngoài cho khách hàng ')
            ->stickyModalFooter()
            ->schema([
                Tabs::make('Tabs')
                    ->tabs([
                        Tab::make('Thông tin đơn hàng')
                            ->icon('heroicon-o-information-circle')
                            ->columns(2)
                            ->schema([
                                RadioCard::make('order_category_id')
                                    ->label('Phân tách khu vực')
                                    ->required()
                                    ->options(function () {
                                        return OrderCategory::query()
                                            // ->whereHas('orderType', fn ($query) => $query->where('code', 'external'))
                                            ->orderBy('sort_order')
                                            ->pluck('code', 'id')
                                            ->toArray();
                                    })
                                    ->live(onBlur: true)
                                    ->color('primary')
                                    ->columns(5)
                                    ->columnSpanFull(),
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
                                // Thông tin hàng hoá
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
                                        ->columnSpan(1),
                                ])
                                    ->label('Thông tin hàng hoá')
                                    ->columns(4)
                                    ->columnSpanFull(),
                                ToggleButtons::make('cargo_type')
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
                                    ->columnSpanFull(),
                                FusedGroup::make([
                                    TextInput::make('pickup_address_detail')
                                        ->label('Số nhà, tên đường')
                                        ->placeholder('Ví dụ: 12 Nguyễn Huệ'),
                                    Select::make('pickup_province_code')
                                        ->label('Tỉnh / Thành phố')
                                        ->placeholder('Chọn tỉnh / thành phố')
                                        ->options(fn (): array => self::getProvinceOptions())
                                        ->searchable()
                                        ->native(false)
                                        ->live()
                                        ->preload()
                                        ->afterStateUpdated(function (Set $set): void {
                                            $set('pickup_ward_code', null);
                                        })
                                        ->required(),
                                    Select::make('pickup_ward_code')
                                        ->label('Phường / Xã')
                                        ->placeholder('Chọn phường / xã')
                                        ->options(fn (Get $get): array => self::getWardOptions($get('pickup_province_code')))
                                        ->searchable()
                                        ->native(false)
                                        ->preload()
                                        ->live()
                                        ->disabled(fn (Get $get): bool => blank($get('pickup_province_code')))
                                        ->required(),

                                ])
                                    ->label('Địa chỉ nhận hàng')
                                    ->columns(3)
                                    ->columnSpanFull(),
                                TextInput::make('pickup_contact')
                                    ->hiddenLabel()
                                    ->placeholder('Người liên hệ nhận hàng')
                                    ->columnSpan(1),
                                TextInput::make('pickup_phone')
                                    ->hiddenLabel()
                                    ->placeholder('Số điện thoại nhận hàng')
                                    ->tel()
                                    ->columnSpan(1),
                                // Select::make('pickup_location_id')
                                //     ->live(onBlur: true)
                                //     ->label('Điểm đi')
                                //     ->relationship('pickupLocation', 'name')
                                //     ->native(false)
                                //     ->required()
                                //     ->columnSpanFull(),
                                Repeater::make('deliveryPoints')
                                    ->label('Địa chỉ giao hàng')
                                    ->helperText(function (Get $get): string {
                                        $orderCategory = OrderCategory::find($get('order_category_id'));

                                        if ($orderCategory !== null) {
                                            return 'Chưa có điểm đến phụ. Mặc định đến: '.$orderCategory->code;
                                        }

                                        return 'Thêm một hoặc nhiều điểm đến cho đơn hàng';
                                    })
                                    ->reorderableWithDragAndDrop()
                                    ->orderColumn('sequence')
                                    ->defaultItems(0)
                                    ->relationship('deliveryPoints')
                                    ->table([
                                        // Columns
                                        TableColumn::make('Địa điểm')
                                            ->markAsRequired()
                                            ->alignment(Alignment::Start),
                                        TableColumn::make('Khu vực phân loại')
                                            ->width('100px'),
                                        TableColumn::make('Người liên hệ')
                                            ->width('150px'),
                                        TableColumn::make('Số điện thoại')
                                            ->width('150px'),
                                    ])
                                    ->compact()
                                    ->schema([
                                        // Forms
                                        TextInput::make('address')
                                            ->label('Số nhà, tên đường')
                                            ->placeholder('Ví dụ: 34 Lê Lợi'),
                                        Select::make('location_id')
                                            ->relationship('location', 'name')
                                            ->native(false)
                                            ->required(),
                                        TextInput::make('contact_person')
                                            ->placeholder('Ví dụ: Nguyễn Văn A'),
                                        TextInput::make('contact_phone')
                                            ->placeholder('Ví dụ: 0901234567')
                                            ->tel(),

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
                                        null,
                                    ))
                                    ->searchPlaceholder('Tìm biển số, loại xe...')
                                    ->required(),
                                DriverPicker::make('driver_id')
                                    ->label('Lái xe')
                                    ->cards(fn (): array => self::resolveDriverCards())
                                    ->searchPlaceholder('Tìm tên, email...')
                                    ->required(),
                            ]),
                    ]),
            ])
            ->action(function (array $data, Schema $schema) use ($forceAssignedWhenTransportProvided): void {
                try {
                    DB::transaction(function () use ($data, $schema, $forceAssignedWhenTransportProvided): void {
                        $createdBy = auth()->id();

                        if ($createdBy === null) {
                            throw new \RuntimeException('Không xác định được người dùng đang đăng nhập.');
                        }

                        $orderTypeId = OrderType::query()
                            ->where('code', 'external')
                            ->value('id');

                        if ($orderTypeId === null) {
                            throw new \RuntimeException('Không tìm thấy loại đơn Hàng ngoài.');
                        }

                        $todayOrderCount = Order::query()
                            ->whereDate('created_at', today())
                            ->count() + 1;

                        $orderCode = sprintf('ORD-%s-%03d', now()->format('Ymd'), $todayOrderCount);
                        $pickupProvinceName = self::resolveProvinceName($data['pickup_province_code'] ?? null);
                        $pickupWardName = self::resolveWardName(
                            $data['pickup_province_code'] ?? null,
                            $data['pickup_ward_code'] ?? null,
                        );

                        $pickupAddressParts = array_filter([
                            $data['pickup_address_detail'] ?? null,
                            $pickupWardName,
                            $pickupProvinceName,
                        ]);

                        $pickupAddress = $pickupAddressParts !== [] ? implode(', ', $pickupAddressParts) : null;

                        $order = Order::query()->create([
                            'order_code' => $orderCode,
                            'order_type_id' => $orderTypeId,
                            'order_category_id' => $data['order_category_id'],
                            'customer_id' => $data['customer_id'],
                            'cargo_name' => $data['cargo_name'] ?? null,
                            'cargo_type' => $data['cargo_type'] ?? 'GCR',
                            'total_packages' => $data['total_packages'] ?? null,
                            'total_weight' => $data['total_weight'] ?? null,
                            'pickup_address' => $pickupAddress,
                            'pickup_contact' => $data['pickup_contact'] ?? null,
                            'pickup_phone' => $data['pickup_phone'] ?? null,
                            'planned_loading_at' => $data['planned_loading_at'] ?? null,
                            'driver_id' => $data['driver_id'] ?? null,
                            'vehicle_id' => $data['vehicle_id'] ?? null,
                            'status' => filled($data['driver_id'] ?? null) || filled($data['vehicle_id'] ?? null)
                                ? OrderStatus::Assigned->value
                                : OrderStatus::Draft->value,
                            'priority' => $data['priority'] ?? Priority::Medium->value,
                            'created_by' => $createdBy,
                            'notes' => $data['notes'] ?? null,
                        ]);

                        $deliveryPoints = collect($schema->getRawState()['deliveryPoints'] ?? [])
                            ->values()
                            ->map(fn (array $deliveryPoint, int $index): array => [
                                'address' => $deliveryPoint['address'] ?? null,
                                'location_id' => $deliveryPoint['location_id'] ?? null,
                                'contact_person' => $deliveryPoint['contact_person'] ?? null,
                                'contact_phone' => $deliveryPoint['contact_phone'] ?? null,
                                'sequence' => $index + 1,
                            ])
                            ->all();

                        if ($deliveryPoints !== []) {
                            $order->deliveryPoints()->createMany($deliveryPoints);
                        }

                        if (filled($data['vehicle_id'] ?? null)) {
                            $vehicle = Vehicle::query()->find($data['vehicle_id']);

                            if ($vehicle !== null) {
                                $vehicle->status = VehicleStatus::Running;

                                if (filled($data['driver_id'] ?? null)) {
                                    $vehicle->current_driver_id = (int) $data['driver_id'];
                                }

                                $vehicle->save();
                            }
                        }

                        // Ensure status reflects assigned transport when a vehicle or driver was provided
                        if ($forceAssignedWhenTransportProvided && (filled($data['vehicle_id'] ?? null) || filled($data['driver_id'] ?? null))) {
                            $order->status = OrderStatus::Assigned->value;
                            $order->save();
                        }
                    });

                    Notification::make()
                        ->title('Đơn hàng đã được tạo')
                        ->body('Đơn hàng ngoài đã được tạo thành công.')
                        ->success()
                        ->send();
                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi khi tạo đơn hàng')
                        ->body('Đã xảy ra lỗi khi tạo đơn hàng: '.$e->getMessage())
                        ->danger()
                        ->send();
                }
            });

    }

    /**
     * @return array<int|string, string>
     */
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
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * @return array<int|string, string>
     */
    /**
     * @return array<int|string, string>
     */
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
            } catch (Throwable) {
                return [];
            }
        });
    }

    private static function resolveProvinceName(int|string|null $provinceCode): ?string
    {
        if (blank($provinceCode)) {
            return null;
        }

        return self::getProvinceOptions()[(string) $provinceCode] ?? null;
    }

    private static function resolveWardName(int|string|null $provinceCode, int|string|null $wardCode): ?string
    {
        if (blank($provinceCode) || blank($wardCode)) {
            return null;
        }

        return self::getWardOptions($provinceCode)[(string) $wardCode] ?? null;
    }
}
