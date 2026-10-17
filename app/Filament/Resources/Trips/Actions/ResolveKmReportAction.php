<?php

namespace App\Filament\Resources\Trips\Actions;

use App\Enums\TripKmReportStatus;
use App\Models\Trip;
use App\Models\TripKmReport;
use App\Services\TripKmAdjustmentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ResolveKmReportAction
{
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'resolve_km_report')
            ->label('Xử lý báo sai Km')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->visible(fn (?Trip $record, $livewire = null): bool => static::resolveRecord($record, $livewire)?->latestPendingKmReport !== null)
            ->beforeFormFilled(function (Action $action, ?Trip $record = null, $livewire = null) {
                $trip = static::resolveRecord($record, $livewire, $action);
                if (! $trip) {
                    $action->cancel();

                    return;
                }

                $pendingReport = $trip->kmReports()->where('status', TripKmReportStatus::Pending)->latest('id')->first();
                if ($pendingReport === null) {
                    $lastReport = $trip->kmReports()->with('resolver')->latest('id')->first();
                    $resolverName = $lastReport?->resolver?->name ?? 'Người dùng khác';
                    $timeStr = $lastReport?->resolved_at ? ' vào lúc '.$lastReport->resolved_at->format('H:i d/m/Y') : '';
                    $statusLabel = $lastReport?->status?->getLabel() ?? 'Đã xử lý';

                    Notification::make()
                        ->warning()
                        ->title('Báo cáo sai Km đã được xử lý')
                        ->body("Báo cáo sai lệch Km của chuyến này đã được {$resolverName} xử lý ({$statusLabel}){$timeStr}. Không cần thao tác lại.")
                        ->send();

                    $action->cancel();
                }
            })
            ->modal()
            ->modalWidth(Width::ExtraLarge)
            ->modalHeading(fn (?Trip $record, $livewire = null): string => 'Xử lý báo sai Km — '.(static::resolveRecord($record, $livewire)?->trip_code ?? ''))
            ->modalContent(function (?Trip $record, $livewire = null): HtmlString {
                $trip = static::resolveRecord($record, $livewire);
                if ($trip === null) {
                    return new HtmlString('<p>Không tìm thấy chuyến đi.</p>');
                }

                $report = $trip->latestPendingKmReport?->loadMissing(['checkpoint', 'driver']);
                if ($report === null) {
                    $lastReport = $trip->kmReports()->with(['resolver', 'checkpoint', 'driver'])->latest('id')->first();
                    if ($lastReport) {
                        $resolverName = e($lastReport->resolver?->name ?? 'Người dùng khác');
                        $timeStr = $lastReport->resolved_at ? ' vào lúc '.$lastReport->resolved_at->format('H:i d/m/Y') : '';
                        $statusLabel = $lastReport->status?->getLabel() ?? 'Đã xử lý';
                        $adminNote = e($lastReport->admin_note ?? '—');

                        return new HtmlString(<<<HTML
                            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-800 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-200 space-y-2">
                                <div class="flex items-center gap-2">
                                    <svg class="w-5 h-5 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <span class="font-bold text-base">Báo cáo sai Km đã được xử lý</span>
                                </div>
                                <p class="text-sm">Báo cáo sai lệch Km của chuyến này đã được <strong>{$resolverName}</strong> xử lý (<strong>{$statusLabel}</strong>){$timeStr}.</p>
                                <p class="text-xs text-gray-600 dark:text-gray-400">Ghi chú: {$adminNote}</p>
                            </div>
                        HTML);
                    }

                    return new HtmlString('<p>Không có báo cáo nào cần xử lý.</p>');
                }

                $photoHtml = '';
                if ($report->photo_path) {
                    $photoUrl = Storage::disk('public')->url($report->photo_path);
                    $photoHtml = '<div class="mt-3"><span class="text-xs text-gray-500 font-medium">Ảnh chụp Taplo:</span><div class="mt-1"><img src="'.e($photoUrl).'" class="max-w-full max-h-80 rounded-lg border object-contain" alt="Ảnh taplo"></div></div>';
                }

                $delta = (float) $report->reported_km - (float) ($report->system_km ?? 0);
                $deltaFormatted = ($delta >= 0 ? '+' : '').number_format($delta, 1, ',', '.');
                $deltaColor = $delta < 0 ? 'text-red-600' : 'text-green-600';
                $driverName = e($report->driver?->name ?? '—');
                $createdAt = $report->created_at ? $report->created_at->format('H:i d/m/Y') : '—';
                $reportedKmStr = number_format((float) $report->reported_km, 1, ',', '.');
                $systemKmStr = $report->system_km !== null ? number_format((float) $report->system_km, 1, ',', '.') : '—';
                $noteStr = e($report->note ?? '—');

                $cp = $report->checkpoint;
                $cpName = 'Mốc hiện tại';
                if ($cp) {
                    $typeLabel = $cp->checkpoint_type?->getLabel() ?? $cp->checkpoint_type->value;
                    $timeStr = $cp->occurred_at ? $cp->occurred_at->format('H:i d/m') : '';
                    $cpKmStr = $cp->km_reading !== null ? number_format((float) $cp->km_reading, 1, ',', '.').' km' : 'chưa có km';
                    $cpName = "[{$timeStr}] {$typeLabel} ({$cpKmStr})";
                }

                return new HtmlString(<<<HTML
                    <div class="space-y-3">
                        <div class="rounded-lg border border-amber-200 bg-amber-50/70 p-3 text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                            <span class="text-xs font-semibold uppercase tracking-wider text-amber-700 dark:text-amber-300">Bước yêu cầu sửa:</span>
                            <p class="text-base font-bold text-amber-800 dark:text-amber-100">{$cpName}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-4 rounded-lg bg-gray-50 p-4 dark:bg-gray-800">
                            <div>
                                <span class="text-xs text-gray-500">Tài xế báo cáo</span>
                                <p class="font-semibold">{$driverName}</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500">Thời gian gửi</span>
                                <p class="font-semibold">{$createdAt}</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500">Km lái xe báo (taplo)</span>
                                <p class="text-lg font-bold text-amber-600">{$reportedKmStr} km</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500">Km hệ thống ghi nhận</span>
                                <p class="text-lg font-bold">{$systemKmStr} km</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500">Độ lệch chênh lệch</span>
                                <p class="text-lg font-bold {$deltaColor}">{$deltaFormatted} km</p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500">Lý do báo sai</span>
                                <p class="font-medium text-sm text-gray-700 dark:text-gray-300">{$noteStr}</p>
                            </div>
                        </div>
                        {$photoHtml}
                    </div>
                HTML);
            })
            ->schema([
                Hidden::make('report_id')
                    ->default(fn (?Trip $record, $livewire = null, ?Action $action = null) => static::resolveRecord($record, $livewire, $action)?->latestPendingKmReport?->id),

                ToggleButtons::make('decision')
                    ->label('Lựa chọn xử lý')
                    ->options([
                        'ok' => 'OK (Đồng ý sửa theo mốc này)',
                        'reject' => 'Không OK (Từ chối báo cáo)',
                    ])
                    ->colors([
                        'ok' => 'success',
                        'reject' => 'danger',
                    ])
                    ->icons([
                        'ok' => 'heroicon-o-check-circle',
                        'reject' => 'heroicon-o-x-circle',
                    ])
                    ->default('ok')
                    ->inline()
                    ->required()
                    ->live(),

                Select::make('target_checkpoint_id')
                    ->label('Mốc hành trình cần điều chỉnh')
                    ->options(function (?Trip $record, $livewire = null): array {
                        $trip = static::resolveRecord($record, $livewire);
                        if (! $trip) {
                            return [];
                        }
                        $checkpoints = $trip->checkpoints()
                            ->whereNotNull('km_reading')
                            ->orderBy('occurred_at')
                            ->orderBy('id')
                            ->get();

                        if ($checkpoints->isEmpty()) {
                            return ['0' => 'Bắt đầu chuyến (start_km)'];
                        }

                        return $checkpoints->mapWithKeys(function ($cp) {
                            $time = $cp->occurred_at ? $cp->occurred_at->format('H:i d/m') : '';
                            $type = $cp->checkpoint_type?->getLabel() ?? $cp->checkpoint_type?->value;
                            $km = number_format((float) $cp->km_reading, 1, ',', '.').' km';

                            return [$cp->id => "[{$time}] {$type} ({$km})"];
                        })->all();
                    })
                    ->default(function (?Trip $record, $livewire = null) {
                        $trip = static::resolveRecord($record, $livewire);
                        $report = $trip?->latestPendingKmReport;
                        $targetCp = $trip ? app(TripKmAdjustmentService::class)->resolveTargetCheckpoint($trip, $report?->checkpoint_id, $report) : null;

                        return $targetCp?->id ?? '0';
                    })
                    ->required(fn (Get $get) => $get('decision') === 'ok')
                    ->visible(fn (Get $get) => $get('decision') === 'ok')
                    ->live(),

                TextInput::make('corrected_km')
                    ->label('Số km điều chỉnh')
                    ->numeric()
                    ->required(fn (Get $get) => $get('decision') === 'ok')
                    ->visible(fn (Get $get) => $get('decision') === 'ok')
                    ->default(fn (?Trip $record, $livewire = null) => static::resolveRecord($record, $livewire)?->latestPendingKmReport?->reported_km)
                    ->helperText(function (Get $get, ?Trip $record, $livewire = null): string {
                        $trip = static::resolveRecord($record, $livewire);
                        if (! $trip) {
                            return 'Nhập số km thực tế trên taplo.';
                        }
                        $targetId = $get('target_checkpoint_id');
                        $targetIdInt = is_numeric($targetId) && (int) $targetId > 0 ? (int) $targetId : null;
                        $boundaries = app(TripKmAdjustmentService::class)->getKmBoundaries($trip, $targetIdInt, $trip->latestPendingKmReport);

                        $parts = [];
                        if ($boundaries['min_km'] !== null) {
                            $minFmt = number_format($boundaries['min_km'], 1, ',', '.');
                            $parts[] = "tối thiểu {$minFmt} km ({$boundaries['min_label']})";
                        }
                        if ($boundaries['max_km'] !== null) {
                            $maxFmt = number_format($boundaries['max_km'], 1, ',', '.');
                            $parts[] = "tối đa {$maxFmt} km ({$boundaries['max_label']})";
                        }

                        if (empty($parts)) {
                            return 'Nhập số km thực tế trên taplo.';
                        }

                        return 'Giới hạn hợp lệ: '.implode(' — ', $parts).'.';
                    })
                    ->rules([
                        fn (Get $get, ?Trip $record, $livewire = null) => function (string $attribute, $value, \Closure $fail) use ($record, $livewire, $get) {
                            if ($get('decision') !== 'ok') {
                                return;
                            }
                            if ($get('allow_boundary_override')) {
                                return;
                            }
                            $trip = static::resolveRecord($record, $livewire);
                            if (! $trip || ! is_numeric($value)) {
                                return;
                            }

                            $val = (float) $value;
                            $targetId = $get('target_checkpoint_id');
                            $targetIdInt = is_numeric($targetId) && (int) $targetId > 0 ? (int) $targetId : null;
                            $boundaries = app(TripKmAdjustmentService::class)->getKmBoundaries($trip, $targetIdInt, $trip->latestPendingKmReport);

                            if ($boundaries['min_km'] !== null && $val < $boundaries['min_km']) {
                                $minFmt = number_format($boundaries['min_km'], 1, ',', '.');
                                $fail("Số km điều chỉnh ({$value} km) không được nhỏ hơn {$boundaries['min_label']} ({$minFmt} km). Hãy kiểm tra lại hoặc bật 'Bỏ qua kiểm tra giới hạn' nếu muốn ghi đè.");
                            }

                            if ($boundaries['max_km'] !== null && $val > $boundaries['max_km']) {
                                $maxFmt = number_format($boundaries['max_km'], 1, ',', '.');
                                $fail("Số km điều chỉnh ({$value} km) không được lớn hơn {$boundaries['max_label']} ({$maxFmt} km). Hãy kiểm tra lại hoặc bật 'Bỏ qua kiểm tra giới hạn' nếu muốn ghi đè.");
                            }
                        },
                    ])
                    ->live(debounce: 500),

                Placeholder::make('km_deviation_warning')
                    ->label('')
                    ->visible(function (Get $get, ?Trip $record, $livewire = null): bool {
                        if ($get('decision') !== 'ok') {
                            return false;
                        }
                        $val = (float) $get('corrected_km');
                        if ($val <= 0) {
                            return false;
                        }
                        $trip = static::resolveRecord($record, $livewire);
                        $report = $trip?->latestPendingKmReport;
                        $sysKm = (float) ($report?->system_km ?? $trip?->start_km ?? 0);

                        return $sysKm > 0 && abs($val - $sysKm) >= 50.0;
                    })
                    ->content(function (Get $get, ?Trip $record, $livewire = null): HtmlString {
                        $val = (float) $get('corrected_km');
                        $trip = static::resolveRecord($record, $livewire);
                        $report = $trip?->latestPendingKmReport;
                        $sysKm = (float) ($report?->system_km ?? $trip?->start_km ?? 0);
                        $diff = $val - $sysKm;
                        $diffStr = ($diff >= 0 ? '+' : '').number_format($diff, 1, ',', '.');

                        return new HtmlString(<<<HTML
                            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-amber-800 text-xs flex items-center gap-2 dark:border-amber-700 dark:bg-amber-950/60 dark:text-amber-200">
                                <svg class="w-5 h-5 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                                <span><strong>Cảnh báo chênh lệch lớn:</strong> Số km điều chỉnh đang lệch <strong>{$diffStr} km</strong> so với số km hệ thống ghi nhận ban đầu ({$sysKm} km). Vui lòng đối chiếu kỹ ảnh chụp taplo trước khi xác nhận.</span>
                            </div>
                        HTML);
                    }),

                Toggle::make('allow_boundary_override')
                    ->label('Bỏ qua kiểm tra giới hạn mốc trước/sau')
                    ->helperText('Chỉ bật khi các mốc xung quanh cũng bị sai số km và cần điều chỉnh toàn bộ chuỗi.')
                    ->default(false)
                    ->visible(fn (Get $get) => $get('decision') === 'ok')
                    ->live(),

                Textarea::make('admin_note')
                    ->label(fn (Get $get) => $get('decision') === 'reject' ? 'Lý do từ chối *' : 'Ghi chú xử lý')
                    ->required(fn (Get $get) => $get('decision') === 'reject')
                    ->placeholder(fn (Get $get) => $get('decision') === 'reject' ? 'Nhập lý do từ chối báo cáo...' : 'Ghi chú nội bộ (không bắt buộc)...')
                    ->rows(2),
            ])
            ->action(function (array $data, Action $action, ?Trip $record = null, $livewire = null) {
                $trip = static::resolveRecord($record, $livewire, $action);
                if ($trip === null) {
                    $action->cancel();

                    return;
                }

                $alreadyProcessedReport = null;

                $resolved = DB::transaction(function () use ($trip, $data, &$alreadyProcessedReport) {
                    $reportId = ! empty($data['report_id']) ? (int) $data['report_id'] : null;

                    $reportQuery = TripKmReport::where('trip_id', $trip->id)->lockForUpdate();
                    if ($reportId) {
                        $report = (clone $reportQuery)->where('id', $reportId)->first()
                            ?? $reportQuery->latest('id')->first();
                    } else {
                        $report = $reportQuery->latest('id')->first();
                    }

                    if (! $report || $report->status !== TripKmReportStatus::Pending) {
                        $alreadyProcessedReport = $report;

                        return false;
                    }

                    if (($data['decision'] ?? 'ok') === 'reject') {
                        app(TripKmAdjustmentService::class)->rejectReport(
                            $report,
                            $data['admin_note'] ?? 'Từ chối báo cáo',
                            Auth::id(),
                        );
                    } else {
                        $targetCpId = ! empty($data['target_checkpoint_id']) && (int) $data['target_checkpoint_id'] > 0
                            ? (int) $data['target_checkpoint_id']
                            : $report->checkpoint_id;

                        app(TripKmAdjustmentService::class)->resolveReport(
                            $report,
                            (float) $data['corrected_km'],
                            $targetCpId,
                            $data['admin_note'] ?? null,
                            Auth::id(),
                        );
                    }

                    return true;
                });

                if (! $resolved) {
                    $alreadyProcessedReport?->loadMissing('resolver');
                    $resolverName = $alreadyProcessedReport?->resolver?->name ?? 'Người dùng khác';
                    $timeStr = $alreadyProcessedReport?->resolved_at ? ' vào lúc '.$alreadyProcessedReport->resolved_at->format('H:i d/m/Y') : '';
                    $statusLabel = $alreadyProcessedReport?->status?->getLabel() ?? 'Đã xử lý';

                    Notification::make()
                        ->warning()
                        ->title('Báo cáo sai Km đã được xử lý')
                        ->body("Báo cáo sai lệch Km này vừa được {$resolverName} xử lý ({$statusLabel}){$timeStr}. Thao tác của bạn không được thực hiện để tránh cập nhật trùng lặp.")
                        ->send();

                    $action->cancel();

                    return;
                }

                if (($data['decision'] ?? 'ok') === 'reject') {
                    Notification::make()
                        ->danger()
                        ->title('Đã từ chối báo cáo sai km')
                        ->send();
                } else {
                    Notification::make()
                        ->success()
                        ->title('Đã điều chỉnh km mốc hành trình và tính lại chuyến')
                        ->send();
                }
            })
            ->modalSubmitActionLabel('Xác nhận xử lý');
    }

    protected static function resolveRecord(?Trip $record, mixed $livewire, ?Action $action = null): ?Trip
    {
        if ($record instanceof Trip) {
            return $record;
        }

        if ($action && $action->getRecord() instanceof Trip) {
            return $action->getRecord();
        }

        if ($livewire && method_exists($livewire, 'getRecord')) {
            $livewireRecord = $livewire->getRecord();
            if ($livewireRecord instanceof Trip) {
                return $livewireRecord;
            }
        }

        return null;
    }
}
