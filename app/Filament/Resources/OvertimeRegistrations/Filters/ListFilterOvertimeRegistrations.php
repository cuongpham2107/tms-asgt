<?php

namespace App\Filament\Resources\OvertimeRegistrations\Filters;

use App\Enums\OvertimeStatus;
use App\Enums\ShiftType;
use App\Models\User;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

class ListFilterOvertimeRegistrations extends Filter
{
    public static function make(?string $name = 'list_filter_overtime_registrations'): static
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
                    ->placeholder('Tên tài xế, ghi chú...')
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
                    ->options(OvertimeStatus::class)
                    ->live(),
                DatePicker::make('start_date')
                    ->label('Từ ngày')
                    ->placeholder('Chọn ngày bắt đầu')
                    ->default(now()->toDateString())
                    ->native(true)
                    ->prefixIcon('heroicon-o-calendar')
                    ->live(),
                DatePicker::make('end_date')
                    ->label('Đến ngày')
                    ->placeholder('Chọn ngày kết thúc')
                    ->default(now()->toDateString())
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
                                ->orWhere('notes', 'like', "%{$search}%");
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
                        fn (Builder $query, $status): Builder => $query->where('status', $status)
                    )
                    ->when(
                        $data['start_date'] ?? null,
                        fn (Builder $query, $startDate): Builder => $query->whereDate(
                            'overtime_date',
                            '>=',
                            Carbon::parse($startDate)->toDateString(),
                        ),
                    )
                    ->when(
                        $data['end_date'] ?? null,
                        fn (Builder $query, $endDate): Builder => $query->whereDate(
                            'overtime_date',
                            '<=',
                            Carbon::parse($endDate)->toDateString(),
                        ),
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
                    $status = OvertimeStatus::tryFrom($data['status']);
                    $indicators[] = Indicator::make('Trạng thái: '.($status?->getLabel() ?? $data['status']))
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
