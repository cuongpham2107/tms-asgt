<?php

namespace App\Filament\Resources\Users\Filters;

use App\Enums\OnDutyLocation;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class ListFilterUsers extends Filter
{
    public static function make(?string $name = 'list_filter_users'): static
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
                    ->placeholder('Tên, Email, SĐT, CCCD...')
                    ->live(debounce: 500),
                Select::make('role')
                    ->label('Vai trò')
                    ->placeholder('Tất cả vai trò')
                    ->options(fn () => Role::query()->pluck('name', 'name')->toArray())
                    ->live(),
                Select::make('station')
                    ->label('Điểm trực')
                    ->placeholder('Tất cả điểm trực')
                    ->options(OnDutyLocation::class)
                    ->live(),
                Select::make('is_active')
                    ->label('Trạng thái')
                    ->placeholder('Tất cả trạng thái')
                    ->options([
                        '1' => 'Đang hoạt động',
                        '0' => 'Tắt hoạt động',
                    ])
                    ->live(),
                Select::make('cert_status')
                    ->label('Chứng chỉ')
                    ->placeholder('Tất cả chứng chỉ')
                    ->options([
                        'expired' => 'Có CC đã hết hạn',
                        'expiring_soon' => 'Có CC sắp hết hạn (30 ngày)',
                    ])
                    ->live(),
            ])
            ->columns([
                'sm' => 2,
                'md' => 3,
                'xl' => 5,
            ])
            ->query(function (Builder $query, array $data): Builder {
                return $query
                    ->when(
                        $data['search'] ?? null,
                        fn (Builder $query, $search): Builder => $query->where(function (Builder $q) use ($search) {
                            $q->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%")
                                ->orWhere('phone', 'like', "%{$search}%")
                                ->orWhere('cccd', 'like', "%{$search}%");
                        })
                    )
                    ->when(
                        $data['role'] ?? null,
                        fn (Builder $query, $role): Builder => $query->whereHas('roles', fn (Builder $rq) => $rq->where('name', $role))
                    )
                    ->when(
                        $data['station'] ?? null,
                        fn (Builder $query, $station): Builder => $query->where('station', $station)
                    )
                    ->when(
                        isset($data['is_active']) && $data['is_active'] !== null && $data['is_active'] !== '',
                        fn (Builder $query): Builder => $query->where('is_active', (bool) $data['is_active'])
                    )
                    ->when(
                        $data['cert_status'] ?? null,
                        function (Builder $query, $certStatus): Builder {
                            $today = now()->toDateString();
                            if ($certStatus === 'expired') {
                                return $query->where(function (Builder $q) use ($today) {
                                    $q->where(fn ($sub) => $sub->whereNotNull('license_expiry_date')->where('license_expiry_date', '<', $today))
                                        ->orWhere(fn ($sub) => $sub->whereNotNull('aviation_security_cert_expiry_date')->where('aviation_security_cert_expiry_date', '<', $today))
                                        ->orWhere(fn ($sub) => $sub->whereNotNull('dangerous_goods_cert_expiry_date')->where('dangerous_goods_cert_expiry_date', '<', $today));
                                });
                            }
                            if ($certStatus === 'expiring_soon') {
                                $in30Days = now()->addDays(30)->toDateString();

                                return $query->where(function (Builder $q) use ($today, $in30Days) {
                                    $q->whereBetween('license_expiry_date', [$today, $in30Days])
                                        ->orWhereBetween('aviation_security_cert_expiry_date', [$today, $in30Days])
                                        ->orWhereBetween('dangerous_goods_cert_expiry_date', [$today, $in30Days]);
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

                if ($data['role'] ?? null) {
                    $indicators[] = Indicator::make('Vai trò: '.$data['role'])
                        ->removeField('role');
                }

                if ($data['station'] ?? null) {
                    $stationLabel = OnDutyLocation::tryFrom($data['station'])?->getLabel() ?? $data['station'];
                    $indicators[] = Indicator::make('Điểm trực: '.$stationLabel)
                        ->removeField('station');
                }

                if (isset($data['is_active']) && $data['is_active'] !== null && $data['is_active'] !== '') {
                    $statusLabel = $data['is_active'] === '1' ? 'Đang hoạt động' : 'Tắt hoạt động';
                    $indicators[] = Indicator::make('Trạng thái: '.$statusLabel)
                        ->removeField('is_active');
                }

                if ($data['cert_status'] ?? null) {
                    $certLabel = match ($data['cert_status']) {
                        'expired' => 'Chứng chỉ đã hết hạn',
                        'expiring_soon' => 'Chứng chỉ sắp hết hạn',
                        default => $data['cert_status'],
                    };
                    $indicators[] = Indicator::make('Chứng chỉ: '.$certLabel)
                        ->removeField('cert_status');
                }

                return $indicators;
            });
    }
}
