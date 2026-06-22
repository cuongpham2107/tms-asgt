<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Locations\Schemas\LocationForm;
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

class CreateOrderHNAction extends CreatesOrderTransportCards
{
    public static function make(bool $forceAssignedWhenTransportProvided = true): Action
    {
        $tabs = [
            Tab::make('Thông tin đơn hàng')
                ->icon('heroicon-o-information-circle')
                ->columns(4)
                ->schema([
                    ToggleButtons::make('area_id')
                        ->label('Khu vực')
                        ->required()
                        ->options(function () {
                            return Area::query()
                                ->where('is_active', true)
                                ->where('type', 'external')
                                ->orderBy('sort_order', 'asc')
                                ->pluck('code', 'id')
                                ->toArray();
                        })
                        ->live()
                        ->inline()
                        ->columnSpanFull(),
                    self::getCustomerIdFormField(false),
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
                        ->createOptionForm(fn (Schema $schema): array => LocationForm::configure($schema)->getComponents())
                        ->columnSpan(2),
                    TextInput::make('pickup_contact')
                        ->label('Người liên hệ nhận hàng')
                        ->columnSpan(1),
                    TextInput::make('pickup_phone')
                        ->label('Số điện thoại nhận hàng')
                        ->tel()
                        ->columnSpan(1),
                    DateTimePicker::make('planned_loading_at')
                        ->label('Thời gian dự kiến đóng hàng')
                        ->seconds(false)
                        ->native(false)
                        ->default(now())
                        ->required()
                        ->prefixIcon(Heroicon::OutlinedCalendarDays)
                        ->columnSpanFull(),
                    self::getDeliveryPointsRepeaterField('external'),

                    TextInput::make('cargo_name')
                        ->label('Tên hàng hoá')
                        ->columnSpanFull()
                        ->columnSpan(2),
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

        return Action::make('create_order_hang_ngoai_action')
            ->label('Tạo đơn hàng ngoài')
            ->size('lg')
            ->icon('heroicon-o-truck')
            ->modalSubmitAction(fn (Action $action): Action => $action->label('Tạo'))
            ->extraAttributes([
                'class' => 'text-white font-bold [&_.fi-icon]:text-white! bg-[#4CAF50] cursor-pointer hover:bg-[#45a049] transition-colors',
            ])
            ->slideOver()
            ->modal()
            ->modalWidth(Width::SevenExtraLarge)
            ->modalHeading('Tạo đơn hàng ngoài')
            ->modalDescription('Tạo đơn hàng ngoài cho khách hàng ')
            ->stickyModalFooter()
            ->schema([
                Tabs::make('Tabs')
                    ->tabs($tabs),

            ])
            ->action(function (array $data, Schema $schema) use ($forceAssignedWhenTransportProvided): void {
                try {
                    self::createSingleOrder($data, $schema, 'external', $forceAssignedWhenTransportProvided);

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
}
