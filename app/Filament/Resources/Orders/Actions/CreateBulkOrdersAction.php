<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\Priority;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderCategory;
use App\Models\OrderType;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\FusedGroup;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class CreateBulkOrdersAction
{
    public static function make(bool $forceAssignedWhenTransportProvided = true): Action
    {
        return Action::make('create_bulk_orders_action')
            ->label('Tạo nhiều đơn hàng')
            ->size('lg')
            ->icon('heroicon-o-squares-plus')
            ->color('success')
            ->modal()
            ->modalWidth('7xl')
            ->modalHeading('Tạo/Phân tách nhiều đơn hàng cùng tuyến')
            ->modalDescription('Khai báo thông tin lộ trình chung, sau đó phân chia hàng hóa cho từng xe vận chuyển tương ứng.')
            ->stickyModalFooter()
            ->schema([
                Grid::make(12)
                    ->schema([
                        // Phân vùng 1: Thông tin Tuyến đường & Hành trình chung (Chiếm 7 cột)
                        Section::make('Thông tin Tuyến đường & Hành trình chung')
                            ->description('Khai báo khách hàng, hành trình và các điểm đến của chuyến đi.')
                            ->columnSpan(7)
                            ->schema([
                                Grid::make(2)
                                    ->schema([
                                        ToggleButtons::make('order_type_code')
                                            ->label('Loại đơn hàng')
                                            ->options([
                                                'HHHK' => 'Hàng không (HHHK)',
                                                'external' => 'Hàng ngoài (HN)',
                                            ])
                                            ->colors([
                                                'HHHK' => 'primary',
                                                'external' => 'success',
                                            ])
                                            ->default('HHHK')
                                            ->inline()
                                            ->live()
                                            ->required(),
                                        Select::make('order_category_id')
                                            ->label('Khu vực phân loại')
                                            ->options(fn (): array => OrderCategory::query()
                                                ->orderBy('sort_order', 'asc')
                                                ->pluck('code', 'id')
                                                ->toArray())
                                            ->native(false)
                                            ->required(),
                                        Select::make('customer_id')
                                            ->label('Khách hàng')
                                            ->options(fn (): array => Customer::query()->pluck('name', 'id')->toArray())
                                            ->native(false)
                                            ->searchable()
                                            ->preload()
                                            ->required(),
                                        DateTimePicker::make('planned_loading_at')
                                            ->label('Thời gian dự kiến đóng hàng')
                                            ->seconds(false)
                                            ->native(false)
                                            ->required(),
                                    ]),

                                // Pickup Section
                                Grid::make(1)
                                    ->schema([
                                        // Pickup location for HHHK
                                        Select::make('pickup_location_id')
                                            ->label('Điểm nhận hàng (HHHK)')
                                            ->options(fn (): array => Location::query()->pluck('name', 'id')->toArray())->searchable()->preload()
                                            ->native(false)
                                            ->visible(fn (Get $get): bool => $get('order_type_code') === 'HHHK')
                                            ->required(fn (Get $get): bool => $get('order_type_code') === 'HHHK'),

                                        // Pickup address for HN
                                        FusedGroup::make([
                                            TextInput::make('pickup_address_detail')
                                                ->label('Số nhà, tên đường nhận')
                                                ->placeholder('Ví dụ: 12 Nguyễn Huệ')
                                                ->required(fn (Get $get): bool => $get('order_type_code') === 'external'),
                                            Select::make('pickup_province_code')
                                                ->label('Tỉnh / Thành nhận')
                                                ->options(fn (): array => self::getProvinceOptions())
                                                ->searchable()
                                                ->native(false)
                                                ->preload()
                                                ->live()
                                                ->afterStateUpdated(fn (Set $set) => $set('pickup_ward_code', null))
                                                ->required(fn (Get $get): bool => $get('order_type_code') === 'external'),
                                            Select::make('pickup_ward_code')
                                                ->label('Phường / Xã nhận')
                                                ->options(fn (Get $get): array => self::getWardOptions($get('pickup_province_code')))
                                                ->searchable()
                                                ->native(false)
                                                ->preload()
                                                ->live()
                                                ->disabled(fn (Get $get): bool => blank($get('pickup_province_code')))
                                                ->required(fn (Get $get): bool => $get('order_type_code') === 'external'),
                                        ])
                                            ->label('Điểm nhận hàng (Hàng ngoài)')
                                            ->visible(fn (Get $get): bool => $get('order_type_code') === 'external')
                                            ->columns(3)
                                            ->columnSpanFull(),
                                    ]),

                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('cargo_name')
                                            ->label('Tên hàng hoá')
                                            ->placeholder('Ví dụ: Hàng gia dụng')
                                            ->default(fn (Get $get): ?string => $get('order_type_code') === 'HHHK' ? 'Hàng HHHK' : 'Hàng ngoài')
                                            ->required(),
                                        Select::make('cargo_type')
                                            ->label('Loại hàng')
                                            ->options([
                                                'GCR' => 'Hàng thường (GCR)',
                                                'DGR' => 'Hàng nguy hiểm (DGR)',
                                            ])
                                            ->default('GCR')
                                            ->native(false)
                                            ->required(),
                                    ]),

                                // Delivery points repeater (Route)
                                Repeater::make('deliveryPoints')
                                    ->label('Điểm giao hàng (Hành trình chuyến đi)')
                                    ->minItems(1)
                                    ->defaultItems(1)
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
                                    ->compact()
                                    ->schema([
                                        Grid::make(12)
                                            ->schema([
                                                Select::make('location_id')
                                                    ->label('Điểm giao hàng')
                                                    ->options(fn (): array => Location::query()->pluck('name', 'id')->toArray())
                                                    ->searchable()
                                                    ->preload()
                                                    ->native(false)
                                                    ->required()
                                                    ->columnSpan(fn (Get $get): int => $get('../../order_type_code') === 'HHHK' ? 6 : 4),
                                                TextInput::make('address')
                                                    ->label('Số nhà, tên đường giao')
                                                    ->placeholder('Nhập địa chỉ giao chi tiết')
                                                    ->visible(fn (Get $get): bool => $get('../../order_type_code') === 'external')
                                                    ->required(fn (Get $get): bool => $get('../../order_type_code') === 'external')
                                                    ->columnSpan(8),
                                                TextInput::make('contact_person')
                                                    ->label('Người nhận')
                                                    ->placeholder('Họ tên')
                                                    ->visible(fn (Get $get): bool => $get('../../order_type_code') === 'external')
                                                    ->columnSpan(4),
                                                TextInput::make('contact_phone')
                                                    ->label('SĐT nhận')
                                                    ->placeholder('Số điện thoại')
                                                    ->tel()
                                                    ->visible(fn (Get $get): bool => $get('../../order_type_code') === 'external')
                                                    ->columnSpan(3),
                                                TextInput::make('total_packages')
                                                    ->label('Số kiện')
                                                    ->numeric()
                                                    ->columnSpan(fn (Get $get): int => $get('../../order_type_code') === 'HHHK' ? 3 : 2),
                                                TextInput::make('total_weight')
                                                    ->label('Trọng lượng (tấn)')
                                                    ->numeric()
                                                    ->columnSpan(fn (Get $get): int => $get('../../order_type_code') === 'HHHK' ? 3 : 3),
                                            ]),
                                    ])
                                    ->columnSpanFull(),
                            ]),

                        // Phân vùng 2: Phân chia Đơn hàng (Chiếm 5 cột)
                        Section::make('Phân chia Đơn hàng (Đơn tách)')
                            ->description('Khai báo số lượng kiện, trọng lượng và ghi chú cho mỗi đơn hàng tách.')
                            ->columnSpan(5)
                            ->schema([
                                Repeater::make('split_orders')
                                    ->label('Danh sách Đơn tách')
                                    ->minItems(1)
                                    ->defaultItems(1)
                                    ->reorderableWithDragAndDrop()
                                    ->schema([
                                        Grid::make(2)
                                            ->schema([
                                                TextInput::make('total_packages')
                                                    ->label('Số kiện đơn này')
                                                    ->numeric()
                                                    ->required(),
                                                TextInput::make('total_weight')
                                                    ->label('Trọng lượng (tấn)')
                                                    ->numeric()
                                                    ->required(),
                                            ]),
                                        TextInput::make('notes')
                                            ->label('Ghi chú')
                                            ->placeholder('Nhập ghi chú riêng cho đơn này'),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ])
            ->action(function (array $data, Schema $schema): void {
                $createdBy = Auth::id();

                if ($createdBy === null) {
                    Notification::make()
                        ->title('Lỗi hệ thống')
                        ->body('Không xác định được người dùng đăng nhập.')
                        ->danger()
                        ->send();

                    return;
                }

                $splitOrders = $data['split_orders'] ?? [];

                if (count($splitOrders) === 0) {
                    Notification::make()
                        ->title('Cảnh báo')
                        ->body('Vui lòng thêm ít nhất một xe/đơn tách ở Phân chia Đơn hàng.')
                        ->warning()
                        ->send();

                    return;
                }

                $orderTypeCode = $data['order_type_code'] ?? 'HHHK';
                $orderTypeId = OrderType::query()
                    ->where('code', $orderTypeCode)
                    ->value('id');

                if ($orderTypeId === null) {
                    Notification::make()
                        ->title('Lỗi hệ thống')
                        ->body("Không tìm thấy loại đơn hàng: {$orderTypeCode}")
                        ->danger()
                        ->send();

                    return;
                }

                // Compile pickup address parts if HN order
                $pickupAddress = null;
                if ($orderTypeCode === 'external') {
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

                    $pickupAddress = count($pickupAddressParts) > 0
                        ? implode(', ', $pickupAddressParts)
                        : null;
                }

                // Extract delivery points configuration from Section 1
                $deliveryPointsRaw = $data['deliveryPoints'] ?? [];

                try {
                    DB::transaction(function () use (
                        $splitOrders,
                        $createdBy,
                        $orderTypeId,
                        $orderTypeCode,
                        $data,
                        $pickupAddress,
                        $deliveryPointsRaw

                    ): void {
                        $baseCount = Order::query()->whereDate('created_at', '=', now()->toDateString(), 'and')->count();

                        foreach ($splitOrders as $index => $item) {
                            // Generate sequential order code securely
                            $todayOrderCount = $baseCount + $index + 1;
                            $orderCode = sprintf('ORD-%s-%03d', now()->format('Ymd'), $todayOrderCount);

                            $order = Order::query()->create([
                                'order_code' => $orderCode,
                                'order_type_id' => $orderTypeId,
                                'order_category_id' => $data['order_category_id'],
                                'customer_id' => $data['customer_id'],
                                'pickup_location_id' => $orderTypeCode === 'HHHK' ? ($data['pickup_location_id'] ?? null) : null,
                                'pickup_address' => $pickupAddress,
                                'cargo_name' => $data['cargo_name'] ?? null,
                                'cargo_type' => $data['cargo_type'] ?? 'GCR',
                                'total_packages' => $item['total_packages'] ?? null,
                                'total_weight' => $item['total_weight'] ?? null,
                                'planned_loading_at' => $data['planned_loading_at'] ?? null,
                                'status' => OrderStatus::Draft->value,
                                'priority' => Priority::Medium->value,
                                'created_by' => $createdBy,
                                'notes' => $item['notes'] ?? null,
                            ]);

                            // Clone and associate delivery points route
                            $deliveryPoints = collect($deliveryPointsRaw)
                                ->values()
                                ->map(fn (array $deliveryPoint, int $idx): array => [
                                    'location_id' => $deliveryPoint['location_id'] ?? null,
                                    'address' => $deliveryPoint['address'] ?? null,
                                    'contact_person' => $deliveryPoint['contact_person'] ?? null,
                                    'contact_phone' => $deliveryPoint['contact_phone'] ?? null,
                                    'total_packages' => $deliveryPoint['total_packages'] ?? null,
                                    'total_weight' => $deliveryPoint['total_weight'] ?? null,
                                    'sequence' => $idx + 1,
                                    'status' => OrderDeliveryPointStatus::Pending->value,
                                ])
                                ->all();

                            if ($deliveryPoints !== []) {
                                $order->deliveryPoints()->createMany($deliveryPoints);
                            }
                        }
                    });

                    Notification::make()
                        ->title('Thành công')
                        ->body('Đã tạo thành công '.count($splitOrders).' đơn hàng và sao chép tuyến hành trình tương ứng.')
                        ->success()
                        ->send();

                } catch (Throwable $e) {
                    Notification::make()
                        ->title('Lỗi khi tạo loạt đơn')
                        ->body('Đã xảy ra lỗi: '.$e->getMessage())
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
