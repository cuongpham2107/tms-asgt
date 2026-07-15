<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderDeliveryPointStatus;
use App\Enums\OrderStatus;
use App\Enums\Priority;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Area;
use App\Models\Location;
use App\Models\Order;
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
use Filament\Support\RawJs;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateBulkOrdersAction extends CreatesOrderTransportCards
{
    public static function make(bool $forceAssignedWhenTransportProvided = true): Action
    {
        return Action::make('create_bulk_orders_action')
            ->label('Tạo nhiều đơn hàng')
            ->size('lg')
            ->icon('heroicon-o-squares-plus')
            // ->slideOver()
            ->modal()
            ->modalWidth('5xl')
            ->modalSubmitAction(fn (Action $action): Action => $action->label('Tạo'))
            ->modalHeading('Tạo/Phân tách nhiều đơn hàng cùng tuyến')
            ->modalDescription('Khai báo thông tin lộ trình chung, sau đó phân chia hàng hóa cho từng xe vận chuyển tương ứng.')
            ->extraAttributes([
                'class' => 'text-white font-bold [&_.fi-icon]:text-white! bg-gray-500 cursor-pointer hover:bg-gray-600 transition-colors',
            ])
            ->stickyModalFooter()
            ->schema([
                Grid::make(12)
                    ->schema([
                        // Phân vùng 1: Thông tin Tuyến đường & Hành trình chung (Chiếm 7 cột)
                        Section::make('Thông tin Tuyến đường & Hành trình chung')
                            ->description('Khai báo khách hàng, hành trình và các điểm đến của chuyến đi.')
                            ->columnSpanFull()
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
                                        ToggleButtons::make('area_id')
                                            ->label('Khu vực')
                                            ->options(fn (Get $get): array => Area::query()
                                                ->where('is_active', true)
                                                ->when(
                                                    $get('order_type_code'),
                                                    fn ($query, $type) => $query->where('type', $type)
                                                )
                                                ->orderBy('sort_order', 'asc')
                                                ->pluck('code', 'id')
                                                ->toArray())
                                            ->inline()
                                            ->live()
                                            ->required(),
                                        self::getCustomerIdFormField(true),

                                    ]),

                                // Pickup Section
                                Grid::make(2)
                                    ->schema([
                                        // Pickup location for HHHK
                                        Select::make('pickup_location_id')
                                            ->label('Điểm nhận hàng (HHHK)')
                                            ->options(fn (Get $get): array => Location::query()
                                                ->pluck('name', 'id')
                                                ->toArray()
                                            )->searchable()->preload()
                                            ->native(false)
                                            ->visible(fn (Get $get): bool => $get('order_type_code') === 'HHHK')
                                            ->required(false),
                                        DateTimePicker::make('planned_loading_at')
                                            ->label('Thời gian dự kiến đóng hàng')
                                            ->seconds(false)
                                            ->native(true)
                                            ->default(now())
                                            ->required(),
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
                                            ->default(fn (Get $get): ?string => $get('order_type_code') === 'HHHK' ? 'Hàng HHHK' : 'Hàng ngoài'),
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
                                self::getDeliveryPointsRepeaterField(fn (Get $get): ?string => $get('../../order_type_code')),
                                TextInput::make('chargeable_weight')
                                    ->label('Tải trọng tính cước')
                                    ->suffix('tấn')
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->datalist([1.25, 1.5, 2.5, 3.5, 5, 7, 8, 10, 14])
                                    ->visible(fn (Get $get): bool => $get('order_type_code') === 'external'),
                            ]),

                        // Phân vùng 2: Số lượng bản ghi cần tạo (Chiếm 5 cột)
                        Section::make('Số lượng bản ghi cần tạo')
                            ->description('Nhập số đơn hàng muốn tạo với cùng thông tin chung ở phía trên.')
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('records_count')
                                    ->label('Số bản ghi cần tạo')
                                    ->helperText('Ví dụ: nhập 5 để tạo 5 đơn hàng giống nhau về thông tin chung.')
                                    ->mask(RawJs::make('$money($input)'))
                                    ->stripCharacters(',')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(1)
                                    ->required(),
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

                $recordsCount = (int) ($data['records_count'] ?? 1);

                if ($recordsCount < 1) {
                    Notification::make()
                        ->title('Cảnh báo')
                        ->body('Vui lòng nhập số bản ghi cần tạo lớn hơn hoặc bằng 1.')
                        ->warning()
                        ->send();

                    return;
                }

                $orderTypeCode = $data['order_type_code'] ?? 'HHHK';

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
                        $recordsCount,
                        $createdBy,
                        $orderTypeCode,
                        $data,
                        $pickupAddress,
                        $deliveryPointsRaw

                    ): void {
                        $baseCount = Order::query()->whereDate('created_at', '=', now()->toDateString(), 'and')->count();

                        for ($index = 0; $index < $recordsCount; $index++) {
                            // Generate sequential order code securely
                            $orderCode = CreatesOrderTransportCards::generateOrderCode();

                            $order = Order::query()->create([
                                'order_code' => $orderCode,
                                'type' => $orderTypeCode,
                                'area_id' => $data['area_id'],
                                'customer_id' => $data['customer_id'],
                                'pickup_location_id' => $orderTypeCode === 'HHHK' ? ($data['pickup_location_id'] ?? null) : null,
                                'pickup_address' => $pickupAddress,
                                'cargo_name' => $data['cargo_name'] ?? null,
                                'cargo_type' => $data['cargo_type'] ?? 'GCR',
                                'total_packages' => null,
                                'total_weight' => null,
                                'chargeable_weight' => $data['chargeable_weight'] ?? null,
                                'planned_loading_at' => $data['planned_loading_at'] ?? null,
                                'status' => OrderStatus::Draft->value,
                                'priority' => Priority::Medium->value,
                                'created_by' => $createdBy,
                                'notes' => null,
                            ]);

                            // Clone and associate delivery points route
                            $deliveryPoints = collect($deliveryPointsRaw)
                                ->values()
                                ->map(function (array $deliveryPoint, int $idx): array {
                                    $address = null;
                                    if (filled($deliveryPoint['location_id'] ?? null)) {
                                        $address = Location::query()->find($deliveryPoint['location_id'])?->address;
                                    }

                                    return [
                                        'location_id' => $deliveryPoint['location_id'] ?? null,
                                        'address' => $address,
                                        'contact_person' => $deliveryPoint['contact_person'] ?? null,
                                        'contact_phone' => $deliveryPoint['contact_phone'] ?? null,
                                        'total_packages' => $deliveryPoint['total_packages'] ?? null,
                                        'total_weight' => $deliveryPoint['total_weight'] ?? null,
                                        'sequence' => $idx + 1,
                                        'status' => OrderDeliveryPointStatus::Pending->value,
                                    ];
                                })
                                ->all();

                            if ($deliveryPoints !== []) {
                                $order->deliveryPoints()->createMany($deliveryPoints);
                            }
                        }
                    });

                    Notification::make()
                        ->title('Thành công')
                        ->body('Đã tạo thành công '.$recordsCount.' đơn hàng và sao chép tuyến hành trình tương ứng.')
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
}
