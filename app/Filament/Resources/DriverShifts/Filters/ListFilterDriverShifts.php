<?php

namespace App\Filament\Resources\DriverShifts\Filters;

use App\Enums\ShiftType;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

class ListFilterDriverShifts extends Filter
{
    public static function make(?string $name = 'list_filter_driver_shifts'): static
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
                    ->placeholder('Tên tài xế, biển số xe...')
                    ->live(debounce: 500),
                Select::make('driver_id')
                    ->label('Tài xế')
                    ->placeholder('Tất cả tài xế')
                    ->searchable()
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->toArray())
                    ->live(),
                Select::make('shift_type')
                    ->label('Loại ca')
                    ->placeholder('Tất cả loại ca')
                    ->options(ShiftType::class)
                    ->live(),
                Select::make('status')
                    ->label('Trạng thái')
                    ->placeholder('Tất cả trạng thái')
                    ->options([
                        'running' => 'Đang chạy',
                        'ended' => 'Đã kết thúc',
                    ])
                    ->live(),
                DatePicker::make('start_date')
                    ->label('Từ ngày')
                    ->placeholder('Chọn ngày bắt đầu')
                    ->native(true)
                    ->prefixIcon('heroicon-o-calendar')
                    ->live(),
                DatePicker::make('end_date')
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
                        fn (Builder $query, $search): Builder => $query->where(function (Builder $q) use ($search) {
                            $q->whereHas('driver', fn (Builder $dq) => $dq->where('name', 'like', "%{$search}%"))
                                ->orWhereHas('trips.vehicle', fn (Builder $vq) => $vq->where('plate_number', 'like', "%{$search}%"));
                        })
                    )
                    ->when(
                        $data['driver_id'] ?? null,
                        fn (Builder $query, $driverId): Builder => $query->where('driver_id', $driverId)
                    )
                    ->when(
                        $data['shift_type'] ?? null,
                        fn (Builder $query, $shiftType): Builder => $query->where('shift_type', $shiftType)
                    )
                    ->when(
                        $data['status'] ?? null,
                        function (Builder $query, $status): Builder {
                            if ($status === 'running') {
                                return $query->whereNull('end_time');
                            }
                            if ($status === 'ended') {
                                return $query->whereNotNull('end_time');
                            }

                            return $query;
                        }
                    )
                    ->when(
                        ($data['start_date'] ?? null) || ($data['end_date'] ?? null),
                        function (Builder $query) use ($data) {
                            $startDate = ! empty($data['start_date']) ? Carbon::parse($data['start_date'])->startOfDay() : null;
                            $endDate = ! empty($data['end_date']) ? Carbon::parse($data['end_date'])->endOfDay() : null;

                            if ($startDate && ! $endDate) {
                                return $query->where(function (Builder $q) use ($startDate) {
                                    $q->whereDate('start_time', '>=', $startDate)
                                        ->orWhereDate('end_time', '>=', $startDate);
                                });
                            }
                            if (! $startDate && $endDate) {
                                return $query->where(function (Builder $q) use ($endDate) {
                                    $q->whereDate('start_time', '<=', $endDate)
                                        ->orWhereDate('end_time', '<=', $endDate);
                                });
                            }
                            if ($startDate && $endDate) {
                                return $query->where(function (Builder $q) use ($startDate, $endDate) {
                                    $q->whereBetween('start_time', [$startDate, $endDate])
                                        ->orWhereBetween('end_time', [$startDate, $endDate]);
                                });
                            }

                            return $query;
                        }
                    );
            })
            ->indicateUsing(function (array $data): array {
                $indicators = [];

                if ($data['search'] ?? null) {
                    $indicators[] = Indicator::make('Tìm kiếm: '.$data['search'])
                        ->removeField('search');
                }

                if ($data['driver_id'] ?? null) {
                    $driverName = User::find($data['driver_id'])?->name;
                    if ($driverName) {
                        $indicators[] = Indicator::make('Tài xế: '.$driverName)
                            ->removeField('driver_id');
                    }
                }

                if ($data['shift_type'] ?? null) {
                    $shiftType = ShiftType::tryFrom($data['shift_type']);
                    $indicators[] = Indicator::make('Loại ca: '.($shiftType?->getLabel() ?? $data['shift_type']))
                        ->removeField('shift_type');
                }

                if ($data['status'] ?? null) {
                    $statusLabel = match ($data['status']) {
                        'running' => 'Đang chạy',
                        'ended' => 'Đã kết thúc',
                        default => $data['status'],
                    };
                    $indicators[] = Indicator::make('Trạng thái: '.$statusLabel)
                        ->removeField('status');
                }

                if ($data['start_date'] ?? null) {
                    $indicators[] = Indicator::make('Từ ngày: '.Carbon::parse($data['start_date'])->format('d/m/Y'))
                        ->removeField('start_date');
                }

                if ($data['end_date'] ?? null) {
                    $indicators[] = Indicator::make('Đến ngày: '.Carbon::parse($data['end_date'])->format('d/m/Y'))
                        ->removeField('end_date');
                }

                return $indicators;
            });
    }
}
