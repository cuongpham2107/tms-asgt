<?php

namespace App\Filament\Resources\Orders\Actions;

use App\Enums\LocationType;
use App\Filament\Forms\Components\DriverPicker;
use App\Filament\Forms\Components\VehiclePicker;
use App\Filament\Resources\Locations\Schemas\LocationForm;
use App\Filament\Resources\Orders\Actions\Concerns\CreatesOrderTransportCards;
use App\Models\Area;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Throwable;

class CreateOrderHHHKAction extends CreatesOrderTransportCards
{
    public static function make(bool $forceAssignedWhenTransportProvided = true): Action
    {
        $tabs = [
            Tab::make('Thông tin đơn hàng')
                ->icon('heroicon-o-information-circle')
                ->columns(['default' => 1, 'sm' => 2, 'md' => 4])
                ->schema([
                    ToggleButtons::make('area_id')
                        ->label('Khu vực')
                        ->required()
                        ->options(function () {
                            return Area::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order', 'asc')
                                ->pluck('code', 'id')
                                ->toArray();
                        })
                        ->default(function () {
                            return Area::query()
                                ->where('is_active', true)
                                ->where('code', 'NBA')
                                ->value('id')
                                ?? Area::query()->where('is_active', true)->orderBy('sort_order', 'asc')->value('id');
                        })
                        ->live()
                        ->inline()
                        ->columnSpanFull(),
                    self::getCustomerIdFormField(false),
                    Select::make('pickup_location_id')
                        ->label('Điểm nhận hàng')
                        ->options(fn (): array => self::getLocationOptions())
                        ->searchable()
                        ->native(false)
                        ->required()
                        ->createOptionForm(fn (Schema $schema, Get $get): array => LocationForm::configure($schema, $get('area_id'))->getComponents())
                        ->createOptionUsing(function (array $data, Get $get): int {
                            $areaId = $data['area_id'] ?? $get('area_id');
                            if (is_string($areaId) && ! is_numeric($areaId)) {
                                $area = Area::query()->where('code', $areaId)->first();
                                $areaId = $area?->id;
                            }

                            return Location::create(array_merge($data, [
                                'area_id' => $areaId ? (int) $areaId : null,
                                'loc_type' => $data['loc_type'] ?? LocationType::Pickup->value,
                                'is_active' => true,
                            ]))->getKey();
                        })
                        ->columnSpan(['default' => 1, 'sm' => 2, 'md' => 2]),
                    DateTimePicker::make('planned_loading_at')
                        ->label('Thời gian dự kiến đóng hàng')
                        ->seconds(false)
                        ->native(true)
                        // ->hourMode(24)
                        ->default(now())
                        ->prefixIcon(Heroicon::OutlinedCalendarDays)
                        ->required()
                        ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1]),
                    Toggle::make('is_return_trip')
                        ->label('Chuyến quay đầu')
                        ->helperText('Đánh dấu đơn hàng là chuyến quay đầu')
                        ->default(false)
                        ->inline(false)
                        ->columnSpan(['default' => 1, 'sm' => 1, 'md' => 1]),
                    self::getDeliveryPointsRepeaterField('HHHK'),

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
        ];

        if ($forceAssignedWhenTransportProvided) {
            $tabs[] = Tab::make('Phân xe và lái xe')
                ->icon('heroicon-o-truck')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    VehiclePicker::make('vehicle_id')
                        ->label('Phương tiện')
                        ->live()
                        ->afterStateUpdated(fn (Set $set, $state) => self::handleVehicleStateUpdated($set, $state))
                        ->cards(fn (Get $get): array => self::resolveVehicleCards(
                            self::normalizeDecimal($get('total_weight')),
                            self::normalizeInteger($get('pickup_location_id')),
                        ))
                        ->searchPlaceholder('Tìm biển số, loại xe...'),
                    DriverPicker::make('driver_id')
                        ->label('Lái xe')
                        ->live()
                        ->afterStateUpdated(fn (Set $set, $state) => self::handleDriverStateUpdated($set, $state))
                        ->cards(fn (): array => self::resolveDriverCards())
                        ->searchPlaceholder('Tìm tên, email...'),
                ]);
        }

        return Action::make('create_order_hhhk_action')
            ->label('Tạo đơn hàng không')
            ->size('lg')
            ->icon('heroicon-o-globe-asia-australia')
            ->extraAttributes([
                'class' => 'header-action-hhhk font-bold',
            ])
            ->modalSubmitAction(fn (Action $action): Action => $action->label('Tạo'))
            ->extraModalFooterActions(fn (Action $action): array => [
                $action->makeModalSubmitAction('createAndSend', arguments: ['send_immediately' => true])
                    ->label('Tạo và Gửi')
                    ->color('primary'),
            ])
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
            ->action(function (array $data, Schema $schema, array $arguments) use ($forceAssignedWhenTransportProvided) {
                if ($arguments['send_immediately'] ?? false) {
                    $data['send_immediately'] = true;
                }
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
