<?php

namespace App\Filament\Resources\ActivityLogs\Filters;

use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\OrderDeliveryPoint;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

class ListFilterActivityLogs extends Filter
{
    /**
     * @var array<string, string>
     */
    public const EVENT_OPTIONS = [
        'created' => 'Tạo mới',
        'updated' => 'Cập nhật',
        'deleted' => 'Xóa',
        'restored' => 'Khôi phục',
        'api_call' => 'Gọi API',
        'api_error' => 'Lỗi API',
    ];

    /**
     * @var array<string, string>
     */
    public const SUBJECT_TYPE_OPTIONS = [
        'App\Models\Order' => 'Đơn hàng',
        'App\Models\Trip' => 'Chuyến xe',
        'App\Models\TripCheckpoint' => 'Mốc hành trình',
        'App\Models\OrderDeliveryPoint' => 'Điểm giao hàng',
        'App\Models\Vehicle' => 'Phương tiện',
        'App\Models\DriverShift' => 'Ca làm việc',
        'App\Models\User' => 'Tài khoản',
        'App\Models\OrderCharge' => 'Cước đơn hàng',
        'App\Models\OrderChargeFee' => 'Phụ phí đơn hàng',
        'App\Models\FreightRate' => 'Bảng giá cước',
        'App\Models\WaitingFeeRate' => 'Đơn giá phí chờ',
        'App\Models\FeeType' => 'Loại phụ phí',
        'App\Models\Customer' => 'Khách hàng',
        'App\Models\Location' => 'Địa điểm',
    ];

    public static function make(?string $name = 'list_filter_activity_logs'): static
    {
        return parent::make($name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->form([
                TextInput::make('search')
                    ->label('Tìm kiếm')
                    ->placeholder('Mã đơn (ASG-...), mã chuyến (CD-...), mô tả, ID...')
                    ->live(debounce: 500),
                Select::make('event')
                    ->label('Sự kiện')
                    ->placeholder('Tất cả sự kiện')
                    ->options(self::EVENT_OPTIONS)
                    ->live(),
                Select::make('subject_type')
                    ->label('Loại đối tượng')
                    ->placeholder('Tất cả đối tượng')
                    ->searchable()
                    ->options(self::SUBJECT_TYPE_OPTIONS)
                    ->live(),
                Select::make('causer_id')
                    ->label('Người thực hiện')
                    ->placeholder('Tất cả người thực hiện')
                    ->searchable()
                    ->options(fn (): array => [
                        'system' => 'Hệ thống (Tự động)',
                        ...User::query()->orderBy('name')->pluck('name', 'id')->toArray(),
                    ])
                    ->live(),
                DatePicker::make('created_from')
                    ->label('Từ ngày')
                    ->placeholder('Chọn ngày bắt đầu')
                    ->native(true)
                    ->prefixIcon('heroicon-o-calendar')
                    ->live(),
                DatePicker::make('created_until')
                    ->label('Đến ngày')
                    ->placeholder('Chọn ngày kết thúc')
                    ->native(true)
                    ->prefixIcon('heroicon-o-calendar')
                    ->live(),
            ])
            ->columns([
                'sm' => 2,
                'md' => 3,
                'xl' => 6,
            ])
            ->query(function (Builder $query, array $data): Builder {
                return $query
                    ->when(
                        $data['search'] ?? null,
                        function (Builder $query, string $search): Builder {
                            $trimmed = trim($search);
                            $numericId = preg_match('/^#?(\d+)$/', $trimmed, $m) ? (int) $m[1] : null;

                            $matchingOrderIds = Order::withoutGlobalScopes()
                                ->where(function (Builder $oq) use ($trimmed): void {
                                    $oq->whereLike('order_code', "%{$trimmed}%", caseSensitive: false)
                                        ->orWhereIn(
                                            'trip_id',
                                            Trip::withoutGlobalScopes()
                                                ->whereLike('trip_code', "%{$trimmed}%", caseSensitive: false)
                                                ->select('id')
                                        );
                                })
                                ->pluck('id')
                                ->all();

                            $matchingTripIds = Trip::withoutGlobalScopes()
                                ->where(function (Builder $tq) use ($trimmed, $matchingOrderIds): void {
                                    $tq->whereLike('trip_code', "%{$trimmed}%", caseSensitive: false);
                                    if ($matchingOrderIds !== []) {
                                        $tq->orWhereIn(
                                            'id',
                                            Order::withoutGlobalScopes()
                                                ->whereIn('id', $matchingOrderIds)
                                                ->whereNotNull('trip_id')
                                                ->select('trip_id')
                                        );
                                    }
                                })
                                ->pluck('id')
                                ->all();

                            return $query->where(function (Builder $q) use ($trimmed, $numericId, $matchingOrderIds, $matchingTripIds): void {
                                $q->whereLike('description', "%{$trimmed}%", caseSensitive: false)
                                    ->orWhereLike('properties', "%{$trimmed}%", caseSensitive: false)
                                    ->orWhereHasMorph(
                                        'causer',
                                        [User::class],
                                        fn (Builder $uq) => $uq->whereLike('name', "%{$trimmed}%", caseSensitive: false)
                                    );

                                if ($matchingOrderIds !== []) {
                                    $q->orWhere(function (Builder $sq) use ($matchingOrderIds): void {
                                        $sq->where('subject_type', Order::class)
                                            ->whereIn('subject_id', $matchingOrderIds);
                                    })->orWhere(function (Builder $sq) use ($matchingOrderIds): void {
                                        $sq->where('subject_type', OrderDeliveryPoint::class)
                                            ->whereIn(
                                                'subject_id',
                                                OrderDeliveryPoint::withoutGlobalScopes()
                                                    ->whereIn('order_id', $matchingOrderIds)
                                                    ->select('id')
                                            );
                                    })->orWhere(function (Builder $sq) use ($matchingOrderIds): void {
                                        $sq->where('subject_type', OrderCharge::class)
                                            ->whereIn(
                                                'subject_id',
                                                OrderCharge::withoutGlobalScopes()
                                                    ->whereIn('order_id', $matchingOrderIds)
                                                    ->select('id')
                                            );
                                    });
                                }

                                if ($matchingTripIds !== []) {
                                    $q->orWhere(function (Builder $sq) use ($matchingTripIds): void {
                                        $sq->where('subject_type', Trip::class)
                                            ->whereIn('subject_id', $matchingTripIds);
                                    });
                                }

                                if ($matchingOrderIds !== [] || $matchingTripIds !== []) {
                                    $q->orWhere(function (Builder $sq) use ($matchingOrderIds, $matchingTripIds): void {
                                        $sq->where('subject_type', TripCheckpoint::class)
                                            ->whereIn(
                                                'subject_id',
                                                TripCheckpoint::withoutGlobalScopes()
                                                    ->where(function (Builder $cq) use ($matchingOrderIds, $matchingTripIds): void {
                                                        if ($matchingOrderIds !== []) {
                                                            $cq->whereIn('order_id', $matchingOrderIds);
                                                        }
                                                        if ($matchingTripIds !== []) {
                                                            $cq->orWhereIn('trip_id', $matchingTripIds);
                                                        }
                                                    })
                                                    ->select('id')
                                            );
                                    });
                                }

                                $q->orWhere(function (Builder $sq) use ($trimmed): void {
                                    $sq->where('subject_type', Vehicle::class)
                                        ->whereIn(
                                            'subject_id',
                                            Vehicle::withoutGlobalScopes()
                                                ->whereLike('plate_number', "%{$trimmed}%", caseSensitive: false)
                                                ->select('id')
                                        );
                                });

                                if ($numericId !== null) {
                                    $q->orWhere('subject_id', $numericId)
                                        ->orWhere('id', $numericId);
                                }
                            });
                        }
                    )
                    ->when(
                        $data['event'] ?? null,
                        fn (Builder $query, string $event): Builder => $query->where('event', $event)
                    )
                    ->when(
                        $data['subject_type'] ?? null,
                        fn (Builder $query, string $subjectType): Builder => $query->where('subject_type', $subjectType)
                    )
                    ->when(
                        $data['causer_id'] ?? null,
                        function (Builder $query, string $causerId): Builder {
                            if ($causerId === 'system') {
                                return $query->whereNull('causer_id');
                            }

                            return $query->where('causer_id', (int) $causerId);
                        }
                    )
                    ->when(
                        $data['created_from'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date)
                    )
                    ->when(
                        $data['created_until'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date)
                    );
            })
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if ($data['search'] ?? null) {
                    $indicators[] = Indicator::make('Tìm kiếm: '.$data['search'])
                        ->removeField('search');
                }

                if ($data['event'] ?? null) {
                    $label = self::EVENT_OPTIONS[$data['event']] ?? $data['event'];
                    $indicators[] = Indicator::make('Sự kiện: '.$label)
                        ->removeField('event');
                }

                if ($data['subject_type'] ?? null) {
                    $label = self::SUBJECT_TYPE_OPTIONS[$data['subject_type']] ?? class_basename((string) $data['subject_type']);
                    $indicators[] = Indicator::make('Đối tượng: '.$label)
                        ->removeField('subject_type');
                }

                if ($data['causer_id'] ?? null) {
                    $causerName = $data['causer_id'] === 'system'
                        ? 'Hệ thống'
                        : User::find($data['causer_id'])?->name;

                    if ($causerName) {
                        $indicators[] = Indicator::make('Người thực hiện: '.$causerName)
                            ->removeField('causer_id');
                    }
                }

                if ($data['created_from'] ?? null) {
                    $indicators[] = Indicator::make('Từ ngày: '.Carbon::parse($data['created_from'])->format('d/m/Y'))
                        ->removeField('created_from');
                }

                if ($data['created_until'] ?? null) {
                    $indicators[] = Indicator::make('Đến ngày: '.Carbon::parse($data['created_until'])->format('d/m/Y'))
                        ->removeField('created_until');
                }

                return $indicators;
            });
    }
}
