<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\OrderStatus;
use App\Enums\Priority;
use App\Enums\VehicleStatus;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderCategory;
use App\Models\Vehicle;
use CodeWithDennis\FilamentAdvancedChoice\Filament\Forms\Components\RadioCard;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Throwable;

class CreateOrderHHHKAction extends CreatesOrderTransportCards
{
    public static function make(bool $forceAssignedWhenTransportProvided = true): Action
    {
        $tabs = [
            Tab::make('Thông tin đơn hàng')
                ->icon('heroicon-o-information-circle')
                ->columns(2)
                ->schema([
                    RadioCard::make('order_category_id')
                        ->label('Phân tách khu vực')
                        ->required()
                        ->options(function () {
                            return OrderCategory::query()
                                ->where('type', 'HHHK')
                                ->orderBy('sort_order', 'asc')
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
                    Select::make('pickup_location_id')
                        ->live(onBlur: true)
                        ->label('Điểm nhận hàng')
                        ->relationship('pickupLocation', 'name')
                        ->native(false)
                        ->required()
                        ->createOptionForm([
                            TextInput::make('code')
                                ->label('Mã địa điểm')
                                ->required()
                                ->unique('locations', 'code')
                                ->maxLength(30),
                            TextInput::make('name')
                                ->label('Tên đầy đủ')
                                ->required()
                                ->maxLength(255),
                            Textarea::make('address')
                                ->label('Địa chỉ cụ thể')
                                ->columnSpanFull(),
                            Select::make('loc_type')
                                ->label('Loại địa điểm')
                                ->options([
                                    'pickup' => 'Nhận hàng',
                                    'delivery' => 'Giao hàng',
                                    'warehouse' => 'Kho',
                                    'other' => 'Khác',
                                ])
                                ->default('pickup')
                                ->required(),
                        ])
                        ->columnSpanFull(),
                    Repeater::make('deliveryPoints')
                        ->label('Điểm giao hàng')
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
                        ->defaultItems(0)
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
                                        ->columnSpan(4),
                                    TextInput::make('address')
                                        ->label('Địa chỉ giao chi tiết')
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
                                        ->label('Trọng lượng (tấn)')
                                        ->numeric()
                                        ->columnSpan(3),
                                ]),
                        ])
                        ->columnSpanFull(),
                    DateTimePicker::make('planned_loading_at')
                        ->label('Thời gian dự kiến đóng hàng')
                        ->seconds(false)
                        ->native(false)
                        ->default(now())
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
        ];

        if ($forceAssignedWhenTransportProvided) {
            $tabs[] = Tab::make('Phân xe và lái xe')
                ->icon('heroicon-o-truck')
                ->schema([
                    VehiclePicker::make('vehicle_id')
                        ->label('Phương tiện')
                        ->cards(fn (Get $get): array => self::resolveVehicleCards(
                            self::normalizeDecimal($get('total_weight')),
                            self::normalizeInteger($get('pickup_location_id')),
                        ))
                        ->searchPlaceholder('Tìm biển số, loại xe...')
                        ->required(),
                    DriverPicker::make('driver_id')
                        ->label('Lái xe')
                        ->cards(fn (): array => self::resolveDriverCards())
                        ->searchPlaceholder('Tìm tên, email...')
                        ->required(),
                ]);
        }

        return Action::make('create_order_hhhk_action')
            ->label('Tạo đơn hàng không')
            ->size('lg')
            ->icon('heroicon-o-globe-asia-australia')
            ->extraAttributes([
                'class' => 'text-white font-bold [&_.fi-icon]:text-white! bg-[#008fd5] cursor-pointer hover:bg-[#0077b3] transition-colors',
            ])
            ->slideOver()
            ->modal()
            ->modalWidth('5xl')
            ->modalHeading('Tạo đơn hàng không')
            ->modalDescription('Tạo đơn hàng không cho khách hàng HHHK')
            ->stickyModalFooter()
            ->schema([
                Tabs::make('Tabs')
                    ->tabs($tabs),
            ])
            ->action(function (array $data, Schema $schema) use ($forceAssignedWhenTransportProvided) {
                try {
                    DB::transaction(function () use ($data, $schema, $forceAssignedWhenTransportProvided): void {
                        $createdBy = auth()->id();

                        if ($createdBy === null) {
                            throw new \RuntimeException('Không xác định được người dùng đang đăng nhập.');
                        }

                        $order = null;

                        for ($attempt = 0; $attempt < 5; $attempt++) {
                            $orderCode = self::generateOrderCode();

                            try {
                                $order = Order::query()->create([
                                    'order_code' => $orderCode,
                                    'type' => 'HHHK',
                                    'order_category_id' => $data['order_category_id'],
                                    'customer_id' => $data['customer_id'],
                                    'pickup_location_id' => $data['pickup_location_id'] ?? null,
                                    'planned_loading_at' => $data['planned_loading_at'] ?? null,
                                    'driver_id' => $data['driver_id'] ?? null,
                                    'vehicle_id' => $data['vehicle_id'] ?? null,
                                    'total_packages' => $data['total_packages'] ?? null,
                                    'total_weight' => $data['total_weight'] ?? null,
                                    'status' => filled($data['driver_id'] ?? null) || filled($data['vehicle_id'] ?? null)
                                        ? OrderStatus::Assigned->value
                                        : OrderStatus::Draft->value,
                                    'priority' => $data['priority'] ?? Priority::Medium->value,
                                    'created_by' => $createdBy,
                                    'notes' => $data['notes'] ?? null,
                                ]);

                                break;
                            } catch (Throwable $e) {
                                if (! self::isOrderCodeDuplicate($e) || $attempt === 4) {
                                    throw $e;
                                }
                            }
                        }

                        if ($order === null) {
                            throw new \RuntimeException('Không thể tạo mã đơn hàng sau nhiều lần thử.');
                        }
                        $deliveryPoints = collect($schema->getRawState()['deliveryPoints'] ?? [])
                            ->values()
                            ->map(fn (array $deliveryPoint, int $index): array => [
                                'location_id' => $deliveryPoint['location_id'] ?? null,
                                'address' => $deliveryPoint['address'] ?? null,
                                'contact_person' => $deliveryPoint['contact_person'] ?? null,
                                'contact_phone' => $deliveryPoint['contact_phone'] ?? null,
                                'total_packages' => $deliveryPoint['total_packages'] ?? null,
                                'total_weight' => $deliveryPoint['total_weight'] ?? null,
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
                        ->body('Đơn hàng không đã được tạo thành công.')
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
}
