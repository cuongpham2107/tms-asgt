<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Area;
use App\Models\Location;
use App\Models\Vehicle;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
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
                    ToggleButtons::make('area_id')
                        ->label('Khu vực')
                        ->required()
                        ->options(function () {
                            return Area::query()
                                ->where('is_active', true)
                                ->where('type', 'HHHK')
                                ->orderBy('sort_order', 'asc')
                                ->pluck('code', 'id')
                                ->toArray();
                        })
                        ->live()
                        ->inline()
                        ->columnSpanFull(),
                    self::getCustomerIdFormField(false),
                    //     ->columnSpanFull(),
                    Select::make('pickup_location_id')
                        ->live(onBlur: true)
                        ->label('Điểm nhận hàng')
                        ->options(fn (Get $get): array => Location::query()
                            ->pluck('name', 'id')
                            ->toArray()
                        )
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->createOptionForm([
                            ToggleButtons::make('area_id')
                                ->label('Khu vực')
                                ->options(fn (): array => Area::query()
                                    ->where('type', 'HHHK')
                                    ->orderBy('sort_order', 'asc')
                                    ->pluck('code', 'id')
                                    ->toArray()
                                )
                                ->default(fn (Get $get) => $get('area_id'))
                                ->required()
                                ->inline(),
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
                        ]),
                    DateTimePicker::make('planned_loading_at')
                        ->label('Thời gian dự kiến đóng hàng')
                        ->seconds(false)
                        ->native(true)
                        ->default(now())
                        ->prefixIcon(Heroicon::OutlinedCalendarDays)
                        ->required(),
                    self::getDeliveryPointsRepeaterField('HHHK'),

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
                ->columns(2)
                ->schema([
                    VehiclePicker::make('vehicle_id')
                        ->label('Phương tiện')
                        ->live()
                        ->afterStateUpdated(function (Set $set, $state): void {
                            if ($state) {
                                $vehicle = Vehicle::find($state);
                                $set('driver_id', $vehicle?->current_driver_id ?? null);
                            } else {
                                $set('driver_id', null);
                            }
                        })
                        ->cards(fn (Get $get): array => self::resolveVehicleCards(
                            self::normalizeDecimal($get('total_weight')),
                            self::normalizeInteger($get('pickup_location_id')),
                        ))
                        ->searchPlaceholder('Tìm biển số, loại xe...')
                        ->required(),
                    DriverPicker::make('driver_id')
                        ->label('Lái xe')
                        ->live()
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
                'class' => 'text-white font-bold [&_.fi-icon]:text-white! bg-[#008fd5] cursor-pointer hover:bg-[#0077b3] transition-colors ',
            ])
            ->modalSubmitAction(fn (Action $action): Action => $action->label('Tạo'))
            // ->slideOver()
            ->modal()
            ->modalWidth(Width::MaxContent)
            ->modalHeading('Tạo đơn hàng không')
            ->modalDescription('Tạo đơn hàng không cho khách hàng HHHK')
            ->stickyModalFooter()
            ->schema([
                Tabs::make('Tabs')
                    ->tabs($tabs),
            ])
            ->action(function (array $data, Schema $schema) use ($forceAssignedWhenTransportProvided) {
                try {
                    self::createSingleOrder($data, $schema, 'HHHK', $forceAssignedWhenTransportProvided);

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
