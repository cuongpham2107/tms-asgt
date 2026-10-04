<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Models\Trip;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Điều hành điều chỉnh km đã chốt từ GPS. Số GPS gốc được giữ nguyên để đối chiếu.
 */
class AdjustTripKmAction
{
    public static function make(): Action
    {
        return Action::make('adjust_km')
            ->label('Điều chỉnh km')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->visible(fn (Trip $record): bool => $record->km_calculated_at !== null)
            ->modalHeading(fn (Trip $record): string => 'Điều chỉnh km — '.$record->trip_code)
            ->modalDescription(fn (Trip $record): string => sprintf(
                'GPS: tổng %s km, có hàng %s km (phủ GPS %s%%). Số GPS gốc được giữ lại.',
                number_format((float) $record->total_km, 1, ',', '.'),
                number_format((float) $record->total_km_loaded, 1, ',', '.'),
                number_format((float) $record->gps_coverage, 0),
            ))
            ->fillForm(fn (Trip $record): array => [
                'km_adjusted' => $record->km_adjusted ?? $record->total_km,
                'km_adjusted_loaded' => $record->km_adjusted_loaded ?? $record->total_km_loaded,
                'km_adjust_reason' => $record->km_adjust_reason,
            ])
            ->schema([
                TextInput::make('km_adjusted')
                    ->label('Tổng km')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                TextInput::make('km_adjusted_loaded')
                    ->label('Km có hàng')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(fn (Get $get): ?float => is_numeric($get('km_adjusted')) ? (float) $get('km_adjusted') : null)
                    ->required(),
                Textarea::make('km_adjust_reason')
                    ->label('Lý do điều chỉnh')
                    ->rows(2)
                    ->required(),
            ])
            ->action(function (Trip $record, array $data): void {
                $record->update([
                    'km_adjusted' => $data['km_adjusted'],
                    'km_adjusted_loaded' => $data['km_adjusted_loaded'],
                    'km_adjust_reason' => $data['km_adjust_reason'],
                    'km_adjusted_by' => auth()->id(),
                    'km_needs_review' => false,
                ]);

                Notification::make()->success()->title('Đã điều chỉnh km')->send();
            });
    }
}
