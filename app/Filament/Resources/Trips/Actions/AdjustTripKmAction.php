<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Models\Trip;
use App\Models\TripLeg;
use App\Services\Trip\TripLegService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;

/**
 * Điều hành điều chỉnh km đã chốt từ GPS (tổng km hoặc chi tiết từng chặng).
 * Số GPS gốc được giữ nguyên để đối chiếu.
 */
class AdjustTripKmAction
{
    public static function make(): Action
    {
        return Action::make('adjust_km')
            ->label('Điều chỉnh km')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->modalWidth(Width::SixExtraLarge)
            ->visible(fn (Trip $record): bool => $record->km_calculated_at !== null)
            ->modalHeading(fn (Trip $record): string => 'Điều chỉnh km — '.$record->trip_code)
            ->modalDescription(fn (Trip $record): string => sprintf(
                'GPS gốc: tổng %s km, có hàng %s km (phủ GPS %s%%). Số GPS gốc được giữ lại để đối soát.',
                number_format((float) $record->total_km, 1, ',', '.'),
                number_format((float) $record->total_km_loaded, 1, ',', '.'),
                number_format((float) $record->gps_coverage, 0),
            ))
            ->fillForm(function (Trip $record): array {
                $legService = app(TripLegService::class);
                if ($record->exists) {
                    $legService->syncLegs($record);
                    $record->load(['legs.driver', 'legs.toCheckpoint.driver', 'driver']);
                }

                $legsData = $record->legs->map(fn (TripLeg $l) => [
                    'id' => $l->id,
                    'leg_index' => $l->leg_index,
                    'route' => sprintf('Chặng %d: %s ➔ %s', $l->leg_index, $l->from_name, $l->to_name),
                    'driver_name' => $l->driver?->name ?? $l->toCheckpoint?->driver?->name ?? $record->driver?->name ?? '—',
                    'is_loaded' => (bool) $l->is_loaded,
                    'is_loaded_label' => $l->is_loaded ? 'Có hàng' : 'Xe rỗng',
                    'original_km' => number_format((float) $l->distance_km, 1, ',', '.').' km',
                    'distance_km' => (float) $l->distance_km,
                    'distance_adjusted_km' => $l->distance_adjusted_km !== null ? (float) $l->distance_adjusted_km : null,
                    'adjust_reason' => $l->adjust_reason,
                ])->toArray();

                $totalKm = $record->km_adjusted ?? $record->total_km;
                $loadedKm = $record->km_adjusted_loaded ?? $record->total_km_loaded;
                $emptyKm = ($totalKm !== null && $loadedKm !== null)
                    ? round(max(0, (float) $totalKm - (float) $loadedKm), 1)
                    : ($record->total_km_empty !== null ? (float) $record->total_km_empty : null);

                return [
                    'km_adjusted' => $totalKm,
                    'km_adjusted_loaded' => $loadedKm,
                    'km_adjusted_empty' => $emptyKm,
                    'km_adjust_reason' => $record->km_adjust_reason,
                    'legs' => $legsData,
                ];
            })
            ->schema([
                Section::make('Khoảng cách từng chặng')
                    ->description('Điều hành có thể sửa số km thực tế cho từng chặng bên dưới:')
                    ->visible(fn (Trip $record): bool => $record->legs()->exists() || $record->checkpoints()->count() >= 2)
                    ->schema([
                        Repeater::make('legs')
                            ->label('Danh sách các chặng')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                Hidden::make('id'),
                                Hidden::make('leg_index'),
                                Hidden::make('is_loaded'),
                                Hidden::make('distance_km'),
                                TextInput::make('route')
                                    ->label('Chặng')
                                    ->disabled()
                                    ->columnSpan(4),
                                TextInput::make('driver_name')
                                    ->label('Tài xế')
                                    ->disabled()
                                    ->columnSpan(2),
                                TextInput::make('is_loaded_label')
                                    ->label('Tải')
                                    ->disabled()
                                    ->columnSpan(2),
                                TextInput::make('original_km')
                                    ->label('Km GPS/OSRM')
                                    ->disabled()
                                    ->columnSpan(2),
                                TextInput::make('distance_adjusted_km')
                                    ->label('Km điều chỉnh')
                                    ->numeric()
                                    ->minValue(0)
                                    ->placeholder('Giữ nguyên')
                                    ->columnSpan(2)
                                    ->live(debounce: 500)
                                    ->afterStateUpdated(function (Get $get, Set $set): void {
                                        self::recalculateTotalsFromLegs($get, $set);
                                    }),
                            ])
                            ->columns(12)
                            ->compact(),
                    ]),

                Section::make('Tổng kết km chuyến xe')
                    ->schema([
                        TextInput::make('km_adjusted')
                            ->label('Tổng km')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (is_numeric($state) && is_numeric($get('km_adjusted_loaded'))) {
                                    $set('km_adjusted_empty', round(max(0, (float) $state - (float) $get('km_adjusted_loaded')), 1));
                                }
                            }),
                        TextInput::make('km_adjusted_loaded')
                            ->label('Km có hàng')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn (Get $get): ?float => is_numeric($get('km_adjusted')) ? (float) $get('km_adjusted') : null)
                            ->required()
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if (is_numeric($state) && is_numeric($get('km_adjusted'))) {
                                    $set('km_adjusted_empty', round(max(0, (float) $get('km_adjusted') - (float) $state), 1));
                                }
                            }),
                        TextInput::make('km_adjusted_empty')
                            ->label('Km không hàng')
                            ->numeric()
                            ->minValue(0)
                            ->placeholder('Tự tính')
                            ->live(debounce: 500)
                            ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                if ($state !== null && $state !== '' && is_numeric($state)) {
                                    $loaded = is_numeric($get('km_adjusted_loaded')) ? (float) $get('km_adjusted_loaded') : 0.0;
                                    $set('km_adjusted', round($loaded + (float) $state, 1));
                                }
                            }),
                        Textarea::make('km_adjust_reason')
                            ->label('Lý do điều chỉnh')
                            ->rows(2)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
            ])
            ->action(function (Trip $record, array $data): void {
                $reason = $data['km_adjust_reason'];
                $userId = auth()->id() ?? 1;

                $legsData = $data['legs'] ?? [];
                $hasLegAdjustments = false;

                foreach ($legsData as $legItem) {
                    if (isset($legItem['distance_adjusted_km']) && $legItem['distance_adjusted_km'] !== null && $legItem['distance_adjusted_km'] !== '') {
                        $hasLegAdjustments = true;
                        break;
                    }
                }

                if ($hasLegAdjustments) {
                    // Sửa từng chặng: tính lại tổng + lượt lái từ các chặng.
                    app(TripLegService::class)->adjustLegs($record, $legsData, $reason, $userId);
                } else {
                    // Nhập thẳng tổng km: ghi km_adjusted và phân bổ xuống từng lượt lái.
                    app(TripLegService::class)->applyTotalAdjustment(
                        $record,
                        (float) $data['km_adjusted'],
                        (float) $data['km_adjusted_loaded'],
                        $reason,
                        $userId,
                    );
                }

                Notification::make()->success()->title('Đã điều chỉnh km chuyến và các chặng')->send();
            });
    }

    public static function recalculateTotalsFromLegs(Get $get, Set $set): void
    {
        $legs = $get('../../legs');

        if (empty($legs) || ! is_array($legs)) {
            return;
        }

        $totalKm = 0.0;
        $loadedKm = 0.0;

        foreach ($legs as $leg) {
            $adj = $leg['distance_adjusted_km'] ?? null;
            $orig = isset($leg['distance_km']) && is_numeric($leg['distance_km'])
                ? (float) $leg['distance_km']
                : (float) str_replace([',', ' km'], ['.', ''], (string) ($leg['original_km'] ?? '0'));
            $isLoaded = ! empty($leg['is_loaded']) || (($leg['is_loaded_label'] ?? '') === 'Có hàng');

            $effective = ($adj !== null && $adj !== '' && is_numeric($adj))
                ? (float) $adj
                : $orig;

            $totalKm += $effective;
            if ($isLoaded) {
                $loadedKm += $effective;
            }
        }

        $emptyKm = round(max(0, $totalKm - $loadedKm), 1);

        $set('../../km_adjusted', round($totalKm, 1));
        $set('../../km_adjusted_loaded', round($loadedKm, 1));
        $set('../../km_adjusted_empty', $emptyKm);
    }
}
