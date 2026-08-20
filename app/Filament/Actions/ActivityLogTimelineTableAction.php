<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;

class ActivityLogTimelineTableAction extends Action
{
    protected array $timelineIcons = [
        'created' => 'heroicon-m-check-badge',
        'updated' => 'heroicon-m-pencil-square',
        'deleted' => 'heroicon-m-trash',
    ];

    protected array $timelineIconColors = [
        'created' => 'success',
        'updated' => 'warning',
        'deleted' => 'danger',
    ];

    public static function getDefaultName(): ?string
    {
        return 'Activities';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Lịch sử')
            ->icon('heroicon-m-clock')
            ->color('info')
            ->slideOver()
            ->modalHeading(fn (Model $record): string => 'Lịch sử hoạt động — '.($record->trip_code ?? $record->order_code ?? ('#'.$record->getKey())))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->modalContent(fn (Model $record) => view('filament.actions.activity-log-timeline', [
                'record' => $record,
                'timelineIcons' => $this->timelineIcons,
                'timelineIconColors' => $this->timelineIconColors,
            ]));
    }

    public function timelineIcons(array $icons): static
    {
        $this->timelineIcons = array_merge($this->timelineIcons, $icons);

        return $this;
    }

    public function timelineIconColors(array $colors): static
    {
        $this->timelineIconColors = array_merge($this->timelineIconColors, $colors);

        return $this;
    }
}
