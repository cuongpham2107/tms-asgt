<?php

namespace App\Filament\Resources\ActivityLogs\Tables;

use App\Filament\BaseTable;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\ActivityLogs\Filters\ListFilterActivityLogs;
use App\Models\DriverShift;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderChargeFee;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\ActivityLog\ActivityLogContextResolver;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Spatie\Activitylog\Models\Activity;

class ActivityLogsTable extends BaseTable
{
    public static function configure(Table $table): Table
    {
        return parent::applyDefaults($table)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'causer',
                    'subject' => function (MorphTo $morphTo): void {
                        $morphTo->withoutGlobalScopes()->constrain([
                            Order::class => fn (Builder $q) => $q->with([
                                'trip' => fn ($tq) => $tq->withoutGlobalScopes()->with([
                                    'vehicle' => fn ($vq) => $vq->withoutGlobalScopes(),
                                ]),
                            ]),
                            Trip::class => fn (Builder $q) => $q->with([
                                'orders' => fn ($oq) => $oq->withoutGlobalScopes(),
                                'vehicle' => fn ($vq) => $vq->withoutGlobalScopes(),
                            ]),
                            TripCheckpoint::class => fn (Builder $q) => $q->with([
                                'trip' => fn ($tq) => $tq->withoutGlobalScopes()->with([
                                    'orders' => fn ($oq) => $oq->withoutGlobalScopes(),
                                    'vehicle' => fn ($vq) => $vq->withoutGlobalScopes(),
                                ]),
                                'order' => fn ($oq) => $oq->withoutGlobalScopes()->with([
                                    'trip' => fn ($tq) => $tq->withoutGlobalScopes()->with([
                                        'vehicle' => fn ($vq) => $vq->withoutGlobalScopes(),
                                    ]),
                                ]),
                            ]),
                            OrderDeliveryPoint::class => fn (Builder $q) => $q->with([
                                'order' => fn ($oq) => $oq->withoutGlobalScopes()->with([
                                    'trip' => fn ($tq) => $tq->withoutGlobalScopes()->with([
                                        'vehicle' => fn ($vq) => $vq->withoutGlobalScopes(),
                                    ]),
                                ]),
                                'location' => fn ($lq) => $lq->withoutGlobalScopes(),
                            ]),
                            OrderCharge::class => fn (Builder $q) => $q->with([
                                'order' => fn ($oq) => $oq->withoutGlobalScopes()->with([
                                    'trip' => fn ($tq) => $tq->withoutGlobalScopes()->with([
                                        'vehicle' => fn ($vq) => $vq->withoutGlobalScopes(),
                                    ]),
                                ]),
                            ]),
                            OrderChargeFee::class => fn (Builder $q) => $q->with([
                                'orderCharge' => fn ($cq) => $cq->withoutGlobalScopes()->with([
                                    'order' => fn ($oq) => $oq->withoutGlobalScopes()->with([
                                        'trip' => fn ($tq) => $tq->withoutGlobalScopes()->with([
                                            'vehicle' => fn ($vq) => $vq->withoutGlobalScopes(),
                                        ]),
                                    ]),
                                ]),
                            ]),
                            DriverShift::class => fn (Builder $q) => $q->with([
                                'driver' => fn ($dq) => $dq->withoutGlobalScopes(),
                                'vehicle' => fn ($vq) => $vq->withoutGlobalScopes(),
                            ]),
                        ]);
                    },
                ])
                ->latest('created_at')
                ->latest('id'))
            ->columns([
                TextColumn::make('event')
                    ->label('Sự kiện')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'created' => 'Tạo mới',
                        'updated' => 'Cập nhật',
                        'deleted' => 'Xóa',
                        'restored' => 'Khôi phục',
                        'api_call' => 'Gọi API',
                        'api_error' => 'Lỗi API',
                        default => $state ? ucfirst($state) : '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted', 'api_error' => 'danger',
                        'restored', 'api_call' => 'info',
                        default => 'gray',
                    })
                    ->icon(fn (?string $state): ?string => match ($state) {
                        'created' => 'heroicon-m-check-badge',
                        'updated' => 'heroicon-m-pencil-square',
                        'deleted' => 'heroicon-m-trash',
                        'restored' => 'heroicon-m-arrow-path',
                        'api_call' => 'heroicon-m-globe-alt',
                        'api_error' => 'heroicon-m-exclamation-triangle',
                        default => null,
                    })
                    ->sortable(),

                TextColumn::make('order_code')
                    ->label('Mã đơn')
                    ->badge()
                    ->color('primary')
                    ->placeholder('—')
                    ->state(fn (Activity $record): ?string => self::resolveOrderCode($record))
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::applyOrderCodeSearch($query, $search)),

                TextColumn::make('plate_number')
                    ->label('Biển số xe')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->state(fn (Activity $record): ?string => self::resolvePlateNumber($record))
                    ->copyable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => self::applyPlateNumberSearch($query, $search)),

                TextColumn::make('subject_type')
                    ->label('Đối tượng')
                    ->formatStateUsing(fn (Activity $record): string => self::formatSubjectTitle($record))
                    ->description(fn (Activity $record): ?string => self::formatSubjectSubtitle($record))
                    ->searchable(),

                TextColumn::make('description')
                    ->label('Mô tả')
                    ->limit(80)
                    ->tooltip(fn (Activity $record): ?string => $record->description)
                    ->wrap()
                    ->searchable(),

                TextColumn::make('causer.name')
                    ->label('Người thực hiện')
                    ->default('Hệ thống')
                    ->icon('heroicon-m-user')
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->dateTime('H:i:s d/m/Y')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                ListFilterActivityLogs::make(),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(1)
            ->deferFilters(false)
            ->recordActions([
                Action::make('view_details')
                    ->label('Chi tiết')
                    ->icon('heroicon-m-eye')
                    ->color('info')
                    ->slideOver()
                    ->modalHeading(fn (Activity $record): string => 'Chi tiết hoạt động #'.$record->id)
                    ->modalWidth(Width::TwoExtraLarge)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Đóng')
                    ->modalContent(function (Activity $record) {
                        return view('filament.actions.activity-log-single-detail', [
                            'activity' => $record,
                        ]);
                    }),
            ]);
    }

    public static function formatSubjectTitle(Activity $record): string
    {
        if (! $record->subject_type) {
            return '—';
        }

        $subject = $record->subject;
        $props = self::extractLoggedAttributes($record);

        if ($subject instanceof Order) {
            return "Đơn hàng: {$subject->order_code} (#{$record->subject_id})";
        }

        if ($record->subject_type === Order::class && ! empty($props['order_code'])) {
            return "Đơn hàng: {$props['order_code']} (#{$record->subject_id})";
        }

        if ($subject instanceof Trip) {
            return "Chuyến xe: {$subject->trip_code} (#{$record->subject_id})";
        }

        if ($record->subject_type === Trip::class && ! empty($props['trip_code'])) {
            return "Chuyến xe: {$props['trip_code']} (#{$record->subject_id})";
        }

        if ($subject instanceof TripCheckpoint) {
            $typeLabel = $subject->checkpoint_type?->getLabel();

            return 'Mốc hành trình #'.$record->subject_id.($typeLabel ? " ({$typeLabel})" : '');
        }

        if ($subject instanceof OrderDeliveryPoint) {
            $locationLabel = $subject->location?->code ?: $subject->location?->name;

            return 'Điểm giao hàng #'.$record->subject_id.($locationLabel ? " ({$locationLabel})" : '');
        }

        if ($subject instanceof Vehicle) {
            return "Phương tiện: {$subject->plate_number} (#{$record->subject_id})";
        }

        if ($record->subject_type === Vehicle::class && ! empty($props['plate_number'])) {
            return "Phương tiện: {$props['plate_number']} (#{$record->subject_id})";
        }

        if ($subject instanceof User) {
            return "Tài khoản: {$subject->name} (#{$record->subject_id})";
        }

        if ($record->subject_type === User::class && ! empty($props['name'])) {
            return "Tài khoản: {$props['name']} (#{$record->subject_id})";
        }

        if (isset(ListFilterActivityLogs::SUBJECT_TYPE_OPTIONS[$record->subject_type])) {
            return ListFilterActivityLogs::SUBJECT_TYPE_OPTIONS[$record->subject_type].' #'.$record->subject_id;
        }

        $className = class_basename($record->subject_type);
        $translateCallback = ActivityLogResource::getPlugin()?->getTranslateSubjectCallback();

        if ($translateCallback) {
            $translated = $translateCallback($className);
            if ($translated && $translated !== 'models.'.$className) {
                return $translated.' #'.$record->subject_id;
            }
        }

        return $className.' #'.$record->subject_id;
    }

    public static function formatSubjectSubtitle(Activity $record): ?string
    {
        if (! $record->subject_type) {
            return null;
        }

        $subject = $record->subject;

        if ($subject instanceof Order) {
            return $subject->trip?->trip_code
                ? "Chuyến: {$subject->trip->trip_code}"
                : 'Chưa gán chuyến';
        }

        if ($subject instanceof Trip) {
            $parts = [];
            $orderCodes = $subject->orders->pluck('order_code')->filter()->implode(', ');
            if ($orderCodes !== '') {
                $parts[] = "Đơn: {$orderCodes}";
            }
            if ($subject->vehicle?->plate_number) {
                $parts[] = "Xe: {$subject->vehicle->plate_number}";
            }

            return $parts !== [] ? implode(' · ', $parts) : null;
        }

        if ($subject instanceof TripCheckpoint) {
            $parts = [];
            $orderCode = $subject->order?->order_code
                ?: $subject->trip?->orders?->pluck('order_code')->filter()->implode(', ');

            if ($orderCode) {
                $parts[] = "Đơn: {$orderCode}";
            }
            if ($subject->trip?->trip_code) {
                $parts[] = "Chuyến: {$subject->trip->trip_code}";
            }

            return $parts !== [] ? implode(' · ', $parts) : null;
        }

        if ($subject instanceof OrderDeliveryPoint || $subject instanceof OrderCharge) {
            $parts = [];
            if ($subject->order?->order_code) {
                $parts[] = "Đơn: {$subject->order->order_code}";
            }
            if ($subject->order?->trip?->trip_code) {
                $parts[] = "Chuyến: {$subject->order->trip->trip_code}";
            }

            return $parts !== [] ? implode(' · ', $parts) : null;
        }

        if ($subject instanceof OrderChargeFee) {
            $order = $subject->orderCharge?->order;
            $parts = [];
            if ($order?->order_code) {
                $parts[] = "Đơn: {$order->order_code}";
            }
            if ($order?->trip?->trip_code) {
                $parts[] = "Chuyến: {$order->trip->trip_code}";
            }

            return $parts !== [] ? implode(' · ', $parts) : null;
        }

        if ($subject instanceof DriverShift && $subject->driver?->name) {
            return "Tài xế: {$subject->driver->name}";
        }

        return null;
    }

    public static function resolveOrderCode(Activity $record): ?string
    {
        return ActivityLogContextResolver::current()->resolveOrderCode($record);
    }

    public static function resolvePlateNumber(Activity $record): ?string
    {
        return ActivityLogContextResolver::current()->resolvePlateNumber($record);
    }

    public static function applyOrderCodeSearch(Builder $query, string $rawSearch): Builder
    {
        $resolved = ActivityLogContextResolver::resolveOrderAndTripIdsForOrderSearch($rawSearch);
        if ($resolved['trimmed'] === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($resolved): void {
            ActivityLogContextResolver::applyOrderCodeConditions(
                $q,
                $resolved['trimmed'],
                $resolved['orderIds'],
                $resolved['tripIds'],
            );
        });
    }

    public static function applyPlateNumberSearch(Builder $query, string $rawSearch): Builder
    {
        $resolved = ActivityLogContextResolver::resolveEntityIdsForPlateSearch($rawSearch);
        if ($resolved['trimmed'] === '') {
            return $query;
        }

        if ($resolved['vehicleIds'] === []) {
            return $query->where(function (Builder $q) use ($resolved): void {
                $q->where('subject_type', Vehicle::class)
                    ->whereLike('attribute_changes', "%{$resolved['trimmed']}%", caseSensitive: false);
            });
        }

        return $query->where(function (Builder $q) use ($resolved): void {
            ActivityLogContextResolver::applyPlateNumberConditions(
                $q,
                $resolved['vehicleIds'],
                $resolved['tripIds'],
                $resolved['orderIds'],
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractLoggedAttributes(Activity $record): array
    {
        return ActivityLogContextResolver::extractLoggedAttributes($record);
    }
}
