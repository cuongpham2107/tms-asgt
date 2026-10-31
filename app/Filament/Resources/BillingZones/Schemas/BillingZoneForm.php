<?php

namespace App\Filament\Resources\BillingZones\Schemas;

use App\Models\BillingZone;
use App\Models\Location;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class BillingZoneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('Mã viết tắt')
                    ->placeholder('VD: KCN YÊN PHONG')
                    ->helperText('Dùng trong mã giá; tự viết hoa.')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, ?string $state): Unique => $rule->where('code', BillingZone::normalizeCode($state))),
                TextInput::make('name')
                    ->label('Tên khu vực')
                    ->placeholder('VD: Kcn Yên Phong')
                    ->required()
                    ->maxLength(255),
                // HasMany nên tự lưu: địa điểm chọn thì gán vào khu vực, bỏ chọn thì gỡ ra.
                Select::make('location_ids')
                    ->label('Địa điểm thuộc khu vực')
                    ->multiple()
                    ->searchable()
                    ->options(fn (): array => Location::query()->orderBy('code')->get(['id', 'code', 'name'])
                        ->mapWithKeys(fn (Location $location): array => [$location->id => "{$location->code} — {$location->name}"])
                        ->all())
                    ->afterStateHydrated(fn (Select $component, ?BillingZone $record) => $component->state($record?->locations()->pluck('id')->all() ?? []))
                    ->saveRelationshipsUsing(function (BillingZone $record, ?array $state): void {
                        $record->locations()->whereNotIn('id', $state ?? [])->update(['billing_zone_id' => null]);
                        Location::query()->whereIn('id', $state ?? [])->update(['billing_zone_id' => $record->id]);
                    })
                    ->dehydrated(false)
                    ->helperText('Một địa điểm chỉ thuộc một khu vực: chọn ở đây sẽ chuyển địa điểm khỏi khu vực cũ.')
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label('Ghi chú')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }
}
