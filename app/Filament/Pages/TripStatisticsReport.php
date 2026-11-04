<?php

namespace App\Filament\Pages;

use App\Enums\CargoType;
use App\Enums\CheckpointType;
use App\Enums\FreightRateType;
use App\Enums\OrderType;
use App\Enums\SurchargeType;
use App\Filament\Resources\FreightRates\Schemas\FreightRateForm;
use App\Filament\Traits\HasTripStatistics;
use App\Models\FeeType;
use App\Models\FreightRate;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderCharge;
use App\Models\Trip;
use App\Models\TripCheckpoint;
use App\Services\Billing\OrderChargeCalculator;
use App\Services\Export\TripExcelExportService;
use App\Services\Import\OrderPackagesImporter;
use BackedEnum;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Support\RawJs;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class TripStatisticsReport extends Page
{
    use HasTripStatistics;

    protected static string|UnitEnum|null $navigationGroup = 'Tổng quan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Thống kê';

    protected static ?string $title = 'Bảng thống kê tổng hợp các chuyến đã phục vụ';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.trip-statistics-report';

    #[Url]
    public ?string $startDate = null;

    #[Url]
    public ?string $endDate = null;

    #[Url]
    public ?string $datePreset = 'thisMonth';

    #[Url]
    public string $activeTab = 'HHHK';

    #[Url]
    public string $serviceType = 'HHHK';

    #[Url]
    public ?int $customerId = null;

    #[Url]
    public string $vehicleOwner = 'all';

    #[Url]
    public ?string $search = null;

    #[Url]
    public int $perPage = 25;

    /** Lọc tab Hàng ngoài theo trạng thái cước: 'locked' (đã xuất bảng kê) | 'missing' (chưa có giá) */
    #[Url]
    public ?string $chargeStatus = null;

    public int $page = 1;

    /** Đổi giá trị này sau mỗi lần ghi dữ liệu để widget React nhận cấu hình mới. */
    public int $dataVersion = 0;

    public function mount(): void
    {
        if (blank($this->startDate)) {
            $this->startDate = now()->startOfMonth()->format('Y-m-d');
        }

        if (blank($this->endDate)) {
            $this->endDate = now()->format('Y-m-d');
        }

        $this->syncDatePreset();

        if (filled($this->activeTab) && in_array($this->activeTab, ['HHHK', 'external', 'empty', 'all'])) {
            $this->serviceType = $this->activeTab;
        } elseif (filled($this->serviceType) && in_array($this->serviceType, ['HHHK', 'external', 'empty', 'all'])) {
            $this->activeTab = $this->serviceType;
        } else {
            $this->activeTab = 'HHHK';
            $this->serviceType = 'HHHK';
        }
    }

    public function bumpDataVersion(): void
    {
        $this->dataVersion++;
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->serviceType = $tab;
        $this->page = 1;
    }

    public function updatedActiveTab(): void
    {
        $this->serviceType = $this->activeTab;
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedStartDate(): void
    {
        $this->syncDatePreset();
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedEndDate(): void
    {
        $this->syncDatePreset();
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedServiceType(): void
    {
        $this->activeTab = $this->serviceType;
        $this->page = 1;
    }

    public function updatedCustomerId(): void
    {
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedVehicleOwner(): void
    {
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function updatedPerPage(): void
    {
        $this->page = 1;
    }

    /**
     * Các hàm phân trang mà <x-filament::pagination> gọi (cùng chữ ký với WithPagination của Livewire).
     */
    public function gotoPage(int $page, string $pageName = 'page'): void
    {
        $this->page = max(1, $page);
    }

    public function previousPage(string $pageName = 'page'): void
    {
        $this->gotoPage($this->page - 1);
    }

    public function nextPage(string $pageName = 'page'): void
    {
        $this->gotoPage($this->page + 1);
    }

    public function setDatePreset(string $preset): void
    {
        $this->datePreset = $preset;

        match ($preset) {
            'today' => [
                $this->startDate = now()->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'yesterday' => [
                $this->startDate = now()->subDay()->format('Y-m-d'),
                $this->endDate = now()->subDay()->format('Y-m-d'),
            ],
            'last7days' => [
                $this->startDate = now()->subDays(6)->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'thisMonth' => [
                $this->startDate = now()->startOfMonth()->format('Y-m-d'),
                $this->endDate = now()->format('Y-m-d'),
            ],
            'lastMonth' => [
                $this->startDate = now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'),
                $this->endDate = now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            ],
            default => null,
        };

        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function syncDatePreset(): void
    {
        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        $this->datePreset = match (true) {
            $this->startDate === $today && $this->endDate === $today => 'today',
            $this->startDate === $yesterday && $this->endDate === $yesterday => 'yesterday',
            $this->startDate === now()->subDays(6)->format('Y-m-d') && $this->endDate === $today => 'last7days',
            $this->startDate === now()->startOfMonth()->format('Y-m-d') && $this->endDate === $today => 'thisMonth',
            $this->startDate === now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d') && $this->endDate === now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d') => 'lastMonth',
            default => null,
        };
    }

    public function isDatePresetActive(string $preset): bool
    {
        if ($this->datePreset === $preset) {
            return true;
        }

        $today = now()->format('Y-m-d');
        $yesterday = now()->subDay()->format('Y-m-d');

        return match ($preset) {
            'today' => $this->startDate === $today && $this->endDate === $today,
            'yesterday' => $this->startDate === $yesterday && $this->endDate === $yesterday,
            'last7days' => $this->startDate === now()->subDays(6)->format('Y-m-d') && $this->endDate === $today,
            'thisMonth' => $this->startDate === now()->startOfMonth()->format('Y-m-d') && $this->endDate === $today,
            'lastMonth' => $this->startDate === now()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d') && $this->endDate === now()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d'),
            default => false,
        };
    }

    private const DATE_PRESETS = [
        'today' => 'Hôm nay',
        'yesterday' => 'Hôm qua',
        'last7days' => '7 ngày qua',
        'thisMonth' => 'Tháng này',
        'lastMonth' => 'Tháng trước',
    ];

    public function getSubheading(): string
    {
        $from = Carbon::parse($this->startDate)->format('d/m/Y');
        $to = Carbon::parse($this->endDate)->format('d/m/Y');

        return $from === $to ? "Ngày {$from}" : "Từ {$from} đến {$to}";
    }

    /**
     * Cùng bố cục với các page Thống kê tài xế / Kiểm soát xe: khoảng thời gian và công cụ nằm ở header.
     */
    protected function getHeaderActions(): array
    {
        $presetActions = collect(self::DATE_PRESETS)->map(fn (string $label, string $preset): Action => Action::make('preset_'.$preset)
            ->label($label)
            ->color(fn (): string => $this->isDatePresetActive($preset) ? 'primary' : 'gray')
            ->action(fn () => $this->setDatePreset($preset)));

        return [
            ...$presetActions->values()->all(),
            Action::make('pickRange')
                ->label('Chọn ngày')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color(fn (): string => $this->datePreset === null ? 'primary' : 'gray')
                ->modalWidth(Width::Medium)
                ->modalSubmitActionLabel('Xem')
                ->fillForm(fn (): array => ['startDate' => $this->startDate, 'endDate' => $this->endDate])
                ->schema([
                    DatePicker::make('startDate')->label('Từ ngày')->native(false)->displayFormat('d/m/Y')->required(),
                    DatePicker::make('endDate')->label('Đến ngày')->native(false)->displayFormat('d/m/Y')->afterOrEqual('startDate')->required(),
                ])
                ->action(function (array $data): void {
                    $this->startDate = Carbon::parse($data['startDate'])->format('Y-m-d');
                    $this->endDate = Carbon::parse($data['endDate'])->format('Y-m-d');
                    $this->syncDatePreset();
                    $this->clearBaseRowsCache();
                    $this->page = 1;
                }),
            $this->importPackagesAction(),
            Action::make('export')
                ->label('Xuất Excel (.xlsx)')
                ->icon('heroicon-m-arrow-down-tray')
                ->color('success')
                ->action(fn (): StreamedResponse => $this->exportExcel()),
        ];
    }

    public function resetFilters(): void
    {
        $this->startDate = now()->startOfMonth()->format('Y-m-d');
        $this->endDate = now()->format('Y-m-d');
        $this->datePreset = 'thisMonth';
        $this->customerId = null;
        $this->vehicleOwner = 'all';
        $this->search = null;
        $this->chargeStatus = null;
        $this->clearBaseRowsCache();
        $this->page = 1;
    }

    public function toggleChargeStatus(string $status): void
    {
        $this->chargeStatus = $this->chargeStatus === $status ? null : $status;
        $this->page = 1;
    }

    public function updateOrderMetric(int $orderId, string $field, mixed $value): void
    {
        if (! in_array($field, ['total_packages', 'total_weight'], true)) {
            return;
        }

        /** @var Order|null $order */
        $order = Order::find($orderId);
        if (! $order) {
            Notification::make()
                ->title('Không tìm thấy đơn hàng')
                ->danger()
                ->send();

            return;
        }

        if ($field === 'total_packages') {
            $cleanVal = ($value === '' || $value === null) ? null : max(0, (int) $value);
            $order->total_packages = $cleanVal;
            $label = 'PCS';
            $displayVal = $cleanVal !== null ? number_format($cleanVal) : '0';
        } else {
            $cleanVal = ($value === '' || $value === null) ? null : max(0, round((float) $value, 2));
            $order->total_weight = $cleanVal;
            $label = 'GW';
            $displayVal = $cleanVal !== null ? number_format($cleanVal, 2) : '0';
        }

        $order->save();

        $this->clearBaseRowsCache();
        $this->bumpDataVersion();

        Notification::make()
            ->title("Đã cập nhật {$label}: {$displayVal}")
            ->body($order->order_code ? "Đơn hàng: {$order->order_code}" : null)
            ->success()
            ->send();
    }

    public function importPackagesAction(): Action
    {
        return Action::make('importPackages')
            ->label('Import cân kiện')
            ->icon('heroicon-m-arrow-up-tray')
            ->color('info')
            ->visible(fn (): bool => $this->activeTab === 'HHHK')
            ->modalHeading('Import PCS / GW cho đơn HHHK')
            ->modalDescription('File theo mẫu sheet "1.1 Mẫu Upload dữ liệu cân kiện": cột B Mã đơn hàng, cột F PCS, cột G GW. Ô PCS / GW để trống thì giữ nguyên số cũ.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                FileUpload::make('file')
                    ->label('File Excel')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ])
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var TemporaryUploadedFile $file */
                $file = $data['file'];
                $result = app(OrderPackagesImporter::class)->import($file->getRealPath());

                $this->clearBaseRowsCache();
                $this->bumpDataVersion();

                $notFound = $result['not_found'];
                Notification::make()
                    ->title("Đã cập nhật PCS / GW cho {$result['updated']} đơn")
                    ->body(collect([
                        $result['unchanged'] > 0 ? "{$result['unchanged']} đơn không thay đổi." : null,
                        $notFound !== [] ? 'Không tìm thấy mã đơn: '.implode(', ', array_slice($notFound, 0, 20)).(count($notFound) > 20 ? '…' : '') : null,
                    ])->filter()->implode(' '))
                    ->status($notFound === [] ? 'success' : 'warning')
                    ->persistent($notFound !== [])
                    ->send();
            });
    }

    public function editChargeAction(): Action
    {
        return Action::make('editCharge')
            ->label('Sửa cước')
            ->icon('heroicon-m-pencil-square')
            ->modalHeading(fn (array $arguments): string => 'Cước đơn '.(Order::find($arguments['orderId'] ?? null)?->order_code ?? ''))
            ->modalDescription(fn (array $arguments): ?string => $this->findCharge($arguments)?->isLocked()
                ? 'Dòng cước đang ở trạng thái KHOÁ (đã xuất bảng kê). Nhấn "Mở khoá để sửa" bên dưới để tiếp tục chỉnh sửa.'
                : 'Đổi loại hình / trọng tải tính cước xong hãy bấm "Tính lại" để tra lại đơn giá.')
            ->modalWidth(Width::ScreenTwoExtraLarge)
            ->modalSubmitActionLabel('Lưu')
            ->modalSubmitAction(fn (Action $action, array $arguments): Action => $action->visible(fn (): bool => ! (bool) $this->findCharge($arguments)?->isLocked()))
            ->extraModalFooterActions(fn (Action $action, array $arguments): array => [
                Action::make('unlockAndEdit')
                    ->label('Mở khoá để sửa')
                    ->icon('heroicon-m-lock-open')
                    ->color('warning')
                    ->visible(fn (): bool => (bool) $this->findCharge($arguments)?->isLocked())
                    ->requiresConfirmation()
                    ->modalHeading('Mở khoá dòng cước')
                    ->modalDescription('Mở khoá dòng cước đã xuất bảng kê để tiếp tục chỉnh sửa?')
                    ->modalSubmitActionLabel('Xác nhận mở khoá')
                    ->action(function () use ($arguments): void {
                        $orderId = (int) ($arguments['orderId'] ?? 0);
                        $this->unlockCharge($orderId);
                        $this->mountAction('editCharge', ['orderId' => $orderId]);
                    }),
                Action::make('lockChargeModal')
                    ->label('Khoá cước')
                    ->icon('heroicon-m-lock-closed')
                    ->color('gray')
                    ->visible(fn (): bool => ! (bool) $this->findCharge($arguments)?->isLocked())
                    ->requiresConfirmation()
                    ->modalHeading('Khoá dòng cước')
                    ->modalDescription('Đánh dấu dòng cước này là đã xuất bảng kê (khoá chỉnh sửa)?')
                    ->modalSubmitActionLabel('Xác nhận khoá')
                    ->action(function () use ($arguments): void {
                        $orderId = (int) ($arguments['orderId'] ?? 0);
                        $this->lockCharge($orderId);
                        $this->mountAction('editCharge', ['orderId' => $orderId]);
                    }),
            ])
            ->fillForm(function (array $arguments): array {
                $orderId = (int) ($arguments['orderId'] ?? 0);
                $charge = $this->resolveCharge($orderId);
                $order = Order::with(['trip', 'deliveryPoints', 'tripCheckpoints'])->find($orderId);
                $row = $order === null
                    ? []
                    : (app(TripExcelExportService::class)->getTripSummaryRowsFromOrders(Order::query()->whereKey($orderId))->firstWhere('order_id', $orderId) ?? []);

                $formatTime = fn (mixed $value): ?string => $value instanceof Carbon ? $value->format('Y-m-d H:i:s') : ($value ? Carbon::parse($value)->format('Y-m-d H:i:s') : null);

                return [
                    ...$charge->only(self::EDITABLE_CHARGE_FIELDS),
                    // Số thuần (bỏ ".00" của cột decimal) để mask tiền hiển thị "1,300,000".
                    'base_price' => $charge->base_price === null ? null : (float) $charge->base_price,
                    'waiting_fee' => (float) $charge->waiting_fee,
                    'fees' => $this->feeRowsForForm($charge),
                    'summary_requested_at' => $formatTime($order?->planned_loading_at),
                    'summary_loading_at' => $formatTime($row['time_a'] ?? null),
                    'summary_departed_at' => $formatTime($row['time_b'] ?? null),
                    'summary_arrived_at' => $formatTime($row['time_c'] ?? null),
                    'summary_unloaded_at' => $formatTime($row['time_d'] ?? null),
                ];
            })
            ->schema(fn (array $arguments): array => $this->chargeFormSchema($this->findCharge($arguments), (int) ($arguments['orderId'] ?? 0)))
            ->action(function (array $data, array $arguments): void {
                $orderId = (int) ($arguments['orderId'] ?? 0);
                $charge = $this->resolveCharge($orderId);

                if ($charge->isLocked()) {
                    Notification::make()->title('Dòng cước đã khoá, không thể sửa')->warning()->send();

                    return;
                }

                $charge->fill(Arr::only($data, self::EDITABLE_CHARGE_FIELDS));
                $charge->delivery_notes = collect($data['delivery_notes'] ?? [])
                    ->map(fn (array $note): array => [
                        'number' => filled($note['number'] ?? null) ? trim($note['number']) : null,
                        'actual_weight' => is_numeric($note['actual_weight'] ?? null) ? (float) $note['actual_weight'] : null,
                        'chargeable_weight' => is_numeric($note['chargeable_weight'] ?? null) ? (float) $note['chargeable_weight'] : null,
                    ])
                    ->values()
                    ->all();
                foreach (['return_trip_percent', 'fuel_adjustment_percent', 'vat_percent', 'waiting_minutes', 'waiting_fee'] as $field) {
                    $charge->{$field} ??= 0;
                }
                $charge->rate_type ??= '';
                $charge->surcharge_type ??= '';
                $charge->save();

                $charge->fees()->delete();
                // Form liệt kê sẵn mọi loại phí với số tiền 0 — chỉ lưu những phí có tiền.
                $charge->fees()->createMany(collect($data['fees'] ?? [])
                    ->filter(fn (array $fee): bool => filled($fee['fee_type_id'] ?? null) && (float) ($fee['amount'] ?? 0) > 0)
                    ->map(fn (array $fee): array => ['fee_type_id' => $fee['fee_type_id'], 'amount' => (float) $fee['amount']])
                    ->all());

                $charge->manually_updated_at = now();
                $charge->recalculateTotals()->save();

                $this->saveTimestamps($orderId, $data);

                $this->clearBaseRowsCache();
                $this->bumpDataVersion();

                Notification::make()->title('Đã lưu cước')->success()->send();
            });
    }

    public function recalculateCharge(int $orderId): void
    {
        $order = Order::find($orderId);

        if ($order === null || $order->type !== OrderType::External) {
            return;
        }

        $charge = app(OrderChargeCalculator::class)->calculate($order);

        $this->bumpDataVersion();

        if ($charge->isLocked()) {
            Notification::make()->title('Dòng cước đã khoá, không tính lại')->warning()->send();

            return;
        }

        Notification::make()
            ->title($charge->base_price === null ? 'Chưa tìm thấy giá phù hợp' : 'Đã tính lại cước')
            ->body($order->order_code)
            ->status($charge->base_price === null ? 'warning' : 'success')
            ->send();
    }

    public function unlockCharge(int $orderId): void
    {
        OrderCharge::where('order_id', $orderId)->update(['exported_at' => null, 'exported_by' => null]);

        $this->clearBaseRowsCache();
        $this->bumpDataVersion();

        Notification::make()->title('Đã mở khoá dòng cước')->success()->send();
    }

    public function lockCharge(int $orderId): void
    {
        OrderCharge::where('order_id', $orderId)->update([
            'exported_at' => now(),
            'exported_by' => Auth::id(),
        ]);

        $this->clearBaseRowsCache();
        $this->bumpDataVersion();

        Notification::make()->title('Đã khoá dòng cước')->success()->send();
    }

    /**
     * @var list<string>
     */
    private const EDITABLE_CHARGE_FIELDS = [
        'freight_rate_id',
        'price_code',
        'rate_type',
        'surcharge_type',
        'chargeable_tonnage',
        'is_return_trip',
        'return_trip_percent',
        'delivery_notes',
        'base_price',
        'fuel_adjustment_percent',
        'waiting_minutes',
        'waiting_fee',
        'vat_percent',
        'note',
    ];

    /**
     * Ô nhập tiền: hiển thị phân cách hàng nghìn, lưu số thuần.
     */
    public static function moneyInput(string $name): TextInput
    {
        return TextInput::make($name)
            ->mask(RawJs::make('$money($input)'))
            ->stripCharacters(',')
            ->numeric()
            ->minValue(0)
            ->suffix('đ');
    }

    /**
     * Mọi loại phí đang dùng (theo thứ tự danh mục) kèm số tiền đã nhập, chưa nhập thì 0.
     *
     * @return list<array{fee_type_id: int, amount: float}>
     */
    private function feeRowsForForm(OrderCharge $charge): array
    {
        $amounts = $charge->fees->pluck('amount', 'fee_type_id');

        return FeeType::query()
            ->where('is_active', true)
            ->orWhereIn('id', $amounts->keys())
            ->orderBy('sort')
            ->get()
            ->map(fn (FeeType $feeType): array => [
                'fee_type_id' => $feeType->id,
                'amount' => (float) ($amounts->get($feeType->id) ?? 0),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function findCharge(array $arguments): ?OrderCharge
    {
        return OrderCharge::firstWhere('order_id', $arguments['orderId'] ?? null);
    }

    private function resolveCharge(int $orderId): OrderCharge
    {
        $order = Order::where('type', OrderType::External)->findOrFail($orderId);

        return $order->charge ?? app(OrderChargeCalculator::class)->calculate($order);
    }

    /**
     * @return array<int, mixed>
     */
    /**
     * Form sửa cước: bên trái các trường nhập, bên phải xem chuyến + bảng tính giá (cập nhật theo form).
     *
     * @return array<int, mixed>
     */
    /**
     * Tuyến chưa có đơn giá → mở đúng form "Thêm đơn giá vận chuyển", điền sẵn theo đơn (khách hàng, khu vực đi/đến,
     * loại hàng, loại hình, bậc tải). Lưu xong thì gán khu vực cho địa điểm chưa có khu vực và tra lại đơn giá trên form.
     * Tuyến đã có dòng giá cùng khoá thì gộp bậc tải vào dòng đó thay vì tạo trùng.
     */
    private function addFreightRateAction(?OrderCharge $charge, Closure $refreshPrice): Action
    {
        $order = $charge?->order?->loadMissing(['pickupLocation', 'deliveryPoints.location']);
        $pickup = $order?->pickupLocation;
        $delivery = $order?->deliveryPoints->sortBy('sequence')->first()?->location;

        return Action::make('addFreightRate')
            ->icon(Heroicon::OutlinedPlusCircle)
            ->tooltip('Thêm đơn giá vào bảng giá')
            ->visible(fn (Get $get): bool => $charge !== null && ! $charge->isLocked() && blank($get('freight_rate_id')))
            ->model(FreightRate::class)
            ->modalHeading('Thêm đơn giá vận chuyển')
            ->modalDescription(collect([$pickup, $delivery])->filter(fn (?Location $location): bool => $location !== null && $location->billing_zone_id === null)
                ->map(fn (Location $location): string => "Địa điểm {$location->code} chưa thuộc khu vực nào — khu vực chọn bên dưới sẽ được gán luôn.")
                ->implode(' ') ?: null)
            ->modalSubmitActionLabel('Lưu đơn giá')
            ->schema(fn (Schema $schema): Schema => FreightRateForm::configure($schema))
            ->fillForm(function (Get $get) use ($charge, $pickup, $delivery, $order): array {
                $tonnage = $get('chargeable_tonnage');
                $basePrice = $get('base_price');
                $prices = [];
                if (filled($tonnage)) {
                    $prices[FreightRate::tonnageKey($tonnage)] = filled($basePrice) ? (float) $basePrice : null;
                }

                return [
                    'customer_id' => $charge?->customer_id,
                    'origin_zone_id' => $pickup?->billing_zone_id,
                    'destination_zone_id' => $delivery?->billing_zone_id,
                    'cargo_type' => $charge?->cargo_type ?? CargoType::Gcr,
                    'rate_type' => $get('rate_type'),
                    'prices' => $prices,
                    'notes' => 'Thêm từ modal cước đơn '.($order?->order_code ?? ''),
                ];
            })
            ->action(function (array $data, Get $get, Set $set) use ($charge, $pickup, $delivery, $refreshPrice): void {
                if (blank($data['rate_type'] ?? null) || $data['rate_type'] === 'Chuẩn') {
                    $data['rate_type'] = '';
                }
                $key = Arr::only($data, ['customer_id', 'origin_zone_id', 'destination_zone_id', 'cargo_type', 'rate_type', 'vehicle_type', 'effective_from', 'effective_to']);
                $rate = FreightRate::query()->firstOrNew($key);
                $rate->fill(Arr::except($data, ['prices']));
                $rate->prices = array_replace($rate->prices ?? [], $data['prices'] ?? []);
                $rate->save();

                foreach ([[$pickup, $data['origin_zone_id']], [$delivery, $data['destination_zone_id']]] as [$location, $zoneId]) {
                    if ($location !== null && $location->billing_zone_id === null) {
                        $location->update(['billing_zone_id' => $zoneId]);
                        $location->billing_zone_id = $zoneId;
                    }
                }

                // Nạp lại liên kết của order để previewFreightRate tra được khu vực vừa gán
                $charge?->order?->load(['pickupLocation', 'deliveryPoints.location', 'trip.vehicle']);

                $tonnage = $get('chargeable_tonnage');
                $unitPrice = $rate->priceFor($tonnage);
                $isReturn = (bool) $get('is_return_trip');
                $returnPercent = (float) ($get('return_trip_percent') ?? 0);
                $finalPrice = $unitPrice !== null ? $unitPrice * ($isReturn ? $returnPercent / 100 : 1) : null;

                $set('rate_type', $rate->rate_type);
                $refreshPrice($get, $set);

                // Nếu refreshPrice chưa tự áp được giá thì ưu tiên gán trực tiếp từ bảng giá vừa tạo
                if (blank($get('base_price')) && $finalPrice !== null) {
                    $set('base_price', $finalPrice);
                    $set('freight_rate_id', $rate->id);
                    $set('price_code', $rate->priceCode($tonnage));
                }

                Notification::make()->title('Đã thêm đơn giá vào bảng giá')->success()->send();
            });
    }

    private function chargeFormSchema(?OrderCharge $charge, int $orderId): array
    {
        $money = fn (string $name, string $label): TextInput => self::moneyInput($name)->label($label);
        $order = Order::find($orderId);

        // Đổi loại hình / trọng tải / quay đầu → tra lại đơn giá ngay trên form (chưa lưu).
        $refreshPrice = function (Get $get, Set $set) use ($charge): void {
            if ($charge === null || $charge->isLocked() || $charge->order === null) {
                return;
            }

            $draft = app(OrderChargeCalculator::class)->previewFreightRate($charge->order, $charge, [
                'rate_type' => $get('rate_type'),
                'chargeable_tonnage' => $get('chargeable_tonnage'),
                'is_return_trip' => $get('is_return_trip'),
                'return_trip_percent' => $get('return_trip_percent'),
            ]);

            $set('base_price', $draft->base_price);
            $set('freight_rate_id', $draft->freight_rate_id);
            $set('price_code', $draft->price_code);
        };

        // Đổi mốc thời gian / loại phụ phí / trọng tải → tính lại giờ chờ ngay trên form (chưa lưu).
        $refreshWaiting = function (Get $get, Set $set) use ($charge, $order): void {
            if ($charge === null || $charge->isLocked() || $order === null) {
                return;
            }

            $tA = $get('summary_loading_at') ? Carbon::parse($get('summary_loading_at')) : null;
            $tB = $get('summary_departed_at') ? Carbon::parse($get('summary_departed_at')) : null;
            $tC = $get('summary_arrived_at') ? Carbon::parse($get('summary_arrived_at')) : null;
            $tD = $get('summary_unloaded_at') ? Carbon::parse($get('summary_unloaded_at')) : null;

            $loading = ($tA && $tB) ? (int) abs($tB->diffInMinutes($tA)) : 0;
            $unloading = ($tC && $tD) ? (int) abs($tD->diffInMinutes($tC)) : 0;

            $draft = (clone $charge)->fill([
                'surcharge_type' => (string) ($get('surcharge_type') ?? ''),
                'chargeable_tonnage' => is_numeric($get('chargeable_tonnage')) ? $get('chargeable_tonnage') : null,
            ]);

            $preview = app(OrderChargeCalculator::class)->previewWaiting($order, $draft, $loading, $order->deliveryPoints->count() > 1 ? null : $unloading);

            $set('waiting_minutes', $preview['minutes']);
            $set('waiting_fee', $preview['fee']);
        };

        return [
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Grid::make(1)
                    ->columnSpan(['lg' => 2])
                    ->disabled(fn (): bool => (bool) $charge?->isLocked())
                    ->schema([
                        Section::make('Cách tính cước')
                            ->description('Đổi các ô này thì đơn giá được tra lại theo bảng giá.')
                            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                            ->columns(3)
                            ->compact()
                            ->schema([
                                FreightRateType::select('rate_type')->label('Loại hình tính cước')
                                    ->live()->afterStateUpdated($refreshPrice),
                                SurchargeType::select('surcharge_type')->label('Loại hình tính phụ phí')
                                    ->live()->afterStateUpdated($refreshWaiting),
                                TextInput::make('chargeable_tonnage')->label('Trọng tải tính cước')->numeric()->minValue(0)->suffix('tấn')
                                    ->live(debounce: 500)->afterStateUpdated(function (Get $get, Set $set) use ($refreshPrice, $refreshWaiting): void {
                                        $refreshPrice($get, $set);
                                        $refreshWaiting($get, $set);
                                    }),
                                Toggle::make('is_return_trip')->label('Chuyến quay đầu')->inline(false)
                                    ->live()->afterStateUpdated($refreshPrice),
                                TextInput::make('return_trip_percent')->label('% đơn giá khi quay đầu')->numeric()->minValue(0)->suffix('%')
                                    ->visible(fn (Get $get): bool => (bool) $get('is_return_trip'))
                                    ->live(debounce: 500)->afterStateUpdated($refreshPrice),
                                Hidden::make('freight_rate_id'),
                                Hidden::make('price_code'),
                            ]),
                        Section::make('Phiếu giao hàng & trọng lượng')
                            ->description('Trọng lượng vượt mức khách trả thì tách thành nhiều phiếu — mỗi phiếu là 1 dòng trong bảng kê.')
                            ->icon(Heroicon::OutlinedDocumentDuplicate)
                            ->compact()
                            ->schema([
                                Repeater::make('delivery_notes')
                                    ->hiddenLabel()
                                    ->table([
                                        TableColumn::make('Số phiếu giao hàng'),
                                        TableColumn::make('Trọng lượng hàng thực tế (kg)'),
                                        TableColumn::make('Trọng lượng tính tiền (kg)'),
                                    ])
                                    ->compact()
                                    ->defaultItems(1)
                                    ->addActionLabel('Tách phiếu')
                                    ->schema([
                                        TextInput::make('number'),
                                        TextInput::make('actual_weight')->numeric()->minValue(0),
                                        TextInput::make('chargeable_weight')->numeric()->minValue(0),
                                    ]),
                            ]),
                        Section::make('Đơn giá & giờ chờ')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->columns(3)
                            ->compact()
                            ->schema([
                                $money('base_price', 'Đơn giá vận chuyển gốc')->live(debounce: 500)
                                    ->suffixAction($this->addFreightRateAction($charge, $refreshPrice)),
                                TextInput::make('fuel_adjustment_percent')->label('Tỷ lệ tăng/giảm dầu')->numeric()->suffix('%')->live(debounce: 500),
                                TextInput::make('vat_percent')->label('VAT')->numeric()->minValue(0)->suffix('%')->live(debounce: 500),
                                TextInput::make('waiting_minutes')->label('Thời gian chờ tính tiền')->hint('Đã trừ miễn phí từng chặng')->numeric()->minValue(0)->suffix('phút'),
                                $money('waiting_fee', 'Chi phí giờ chờ')->live(debounce: 500),
                            ]),
                        Section::make('Phí khác & ghi chú')
                            ->icon(Heroicon::OutlinedReceiptPercent)
                            ->compact()
                            ->schema([
                                Repeater::make('fees')
                                    ->hiddenLabel()
                                    ->table([
                                        TableColumn::make('Loại phí')->markAsRequired(),
                                        TableColumn::make('Số tiền (đ)')->markAsRequired(),
                                    ])
                                    ->compact()
                                    ->defaultItems(0)
                                    ->addActionLabel('Thêm phí')
                                    ->live()
                                    ->schema([
                                        Select::make('fee_type_id')
                                            ->options(fn (): array => FeeType::where('is_active', true)->orderBy('sort')->pluck('name', 'id')->all())
                                            ->distinct()
                                            ->required(),
                                        self::moneyInput('amount')->required()->live(debounce: 500),
                                    ]),
                                Textarea::make('note')->label('Ghi chú cước')->rows(2),
                            ]),
                    ]),
                Group::make($this->chargeSummarySchema($charge, $orderId, $refreshWaiting))->columnSpan(['lg' => 1]),
            ]),
        ];
    }

    /**
     * Cột phải: thông tin chuyến, các mốc thời gian và bảng tính giá theo số đang nhập.
     *
     * @return array<int, mixed>
     */
    private function chargeSummarySchema(?OrderCharge $charge, int $orderId, ?Closure $refreshWaiting = null): array
    {
        $order = Order::find($orderId);
        $row = $order === null
            ? []
            : (app(TripExcelExportService::class)->getTripSummaryRowsFromOrders(Order::query()->whereKey($orderId))->firstWhere('order_id', $orderId) ?? []);

        // Diễn giải nguồn gốc đơn giá (theo giá trị đang sửa trên form) và phí giờ chờ tính tự động.
        $calculator = app(OrderChargeCalculator::class);
        $priceNote = fn (Get $get): ?string => $charge === null || $order === null ? null : $calculator->explainPrice($order, (clone $charge)->fill([
            'rate_type' => (string) $get('rate_type'),
            'chargeable_tonnage' => is_numeric($get('chargeable_tonnage')) ? $get('chargeable_tonnage') : null,
            'is_return_trip' => (bool) $get('is_return_trip'),
            'return_trip_percent' => (float) $get('return_trip_percent'),
        ]), filled($get('freight_rate_id')) ? (int) $get('freight_rate_id') : null);

        $waitingNote = function (Get $get) use ($charge, $order, $calculator): ?string {
            if ($charge === null || $order === null) {
                return null;
            }

            $tA = $get('summary_loading_at') ? Carbon::parse($get('summary_loading_at')) : null;
            $tB = $get('summary_departed_at') ? Carbon::parse($get('summary_departed_at')) : null;
            $tC = $get('summary_arrived_at') ? Carbon::parse($get('summary_arrived_at')) : null;
            $tD = $get('summary_unloaded_at') ? Carbon::parse($get('summary_unloaded_at')) : null;

            $loading = ($tA && $tB) ? (int) abs($tB->diffInMinutes($tA)) : null;
            $unloading = ($tC && $tD) ? (int) abs($tD->diffInMinutes($tC)) : null;

            $draft = (clone $charge)->fill([
                'surcharge_type' => (string) ($get('surcharge_type') ?? ''),
                'chargeable_tonnage' => is_numeric($get('chargeable_tonnage')) ? $get('chargeable_tonnage') : null,
            ]);

            return $calculator->explainWaiting($order, $draft, $loading, $unloading);
        };

        $cargoType = $order?->cargo_type ?? CargoType::tryFrom((string) $charge?->cargo_type);
        $duration = fn (?int $minutes): string => $minutes === null ? '—' : $this->formatDuration($minutes);
        $money = fn (?float $value): string => $value === null ? '—' : number_format($value, 0, ',', '.').' đ';
        $entry = fn (string $name, string $label, Heroicon $icon, Closure|string $state, string $iconColor = 'gray'): TextEntry => TextEntry::make($name)
            ->label($label)
            ->inlineLabel()
            ->icon($icon)
            ->iconColor($iconColor)
            ->state($state);
        // Ô tiền có mask "1,300,000" nên state trên form còn dấu phẩy.
        $number = fn (mixed $value): ?float => is_numeric($clean = str_replace(',', '', (string) $value)) ? (float) $clean : null;
        $totals = fn (Get $get): array => OrderCharge::computeTotals(
            basePrice: $number($get('base_price')),
            fuelPercent: (float) $get('fuel_adjustment_percent'),
            waitingFee: (float) $number($get('waiting_fee')),
            otherFees: (float) collect($get('fees') ?? [])->sum(fn (array $fee): float => (float) $number($fee['amount'] ?? 0)),
            vatPercent: (float) $get('vat_percent'),
        );
        $percent = fn (mixed $value): string => rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',').'%';

        return [
            Section::make('Chuyến')
                ->icon(Heroicon::OutlinedTruck)
                ->iconColor('primary')
                ->compact()
                ->schema([
                    $entry('summary_vehicle', 'Biển số', Heroicon::OutlinedIdentification, $row['plate_number'] ?? '—')
                        ->badge()
                        ->color('gray')
                        ->fontFamily(FontFamily::Mono)
                        ->weight(FontWeight::Bold),
                    $entry('summary_driver', 'Lái xe', Heroicon::OutlinedUser, $row['driver_name'] ?? '—')
                        ->weight(FontWeight::Bold),
                    $entry('summary_journey', 'Hành trình', Heroicon::OutlinedMap, $row['journey'] ?? '—')
                        ->weight(FontWeight::Medium),
                    $entry('summary_cargo_type', 'Loại hàng', Heroicon::OutlinedCube, $cargoType?->getLabel() ?? '—')
                        ->badge()
                        ->color($cargoType?->getColor() ?? 'gray'),
                ]),
            Section::make('Mốc thời gian')
                ->icon(Heroicon::OutlinedClock)
                ->iconColor('info')
                ->compact()
                ->disabled(fn (): bool => (bool) $charge?->isLocked())
                ->schema([
                    DateTimePicker::make('summary_requested_at')
                        ->label('Yêu cầu')
                        ->prefixIcon(Heroicon::OutlinedCalendarDays)
                        ->displayFormat('H:i d/m/Y')
                        ->seconds(false)
                        ->native(false),
                    DateTimePicker::make('summary_loading_at')
                        ->label('Đóng hàng')
                        ->prefixIcon(Heroicon::OutlinedArrowDownTray)
                        ->prefixIconColor('info')
                        ->displayFormat('H:i d/m/Y')
                        ->seconds(false)
                        ->native(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated($refreshWaiting),
                    DateTimePicker::make('summary_departed_at')
                        ->label('Xe chạy')
                        ->prefixIcon(Heroicon::OutlinedTruck)
                        ->prefixIconColor('info')
                        ->displayFormat('H:i d/m/Y')
                        ->seconds(false)
                        ->native(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated($refreshWaiting),
                    DateTimePicker::make('summary_arrived_at')
                        ->label('Đến nơi')
                        ->prefixIcon(Heroicon::OutlinedMapPin)
                        ->prefixIconColor('info')
                        ->displayFormat('H:i d/m/Y')
                        ->seconds(false)
                        ->native(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated($refreshWaiting),
                    DateTimePicker::make('summary_unloaded_at')
                        ->label('Hạ hàng')
                        ->prefixIcon(Heroicon::OutlinedArrowUpTray)
                        ->prefixIconColor('info')
                        ->displayFormat('H:i d/m/Y')
                        ->seconds(false)
                        ->native(false)
                        ->live(onBlur: true)
                        ->afterStateUpdated($refreshWaiting),
                    Grid::make(3)->schema([
                        TextEntry::make('summary_loading_duration')
                            ->label('Đóng hàng')
                            ->state(function (Get $get) use ($duration): string {
                                $tA = $get('summary_loading_at') ? Carbon::parse($get('summary_loading_at')) : null;
                                $tB = $get('summary_departed_at') ? Carbon::parse($get('summary_departed_at')) : null;

                                return $duration(($tA && $tB) ? (int) abs($tB->diffInMinutes($tA)) : null);
                            })
                            ->badge()
                            ->color('warning'),
                        TextEntry::make('summary_travel_duration')
                            ->label('Di chuyển')
                            ->state(function (Get $get) use ($duration): string {
                                $tB = $get('summary_departed_at') ? Carbon::parse($get('summary_departed_at')) : null;
                                $tC = $get('summary_arrived_at') ? Carbon::parse($get('summary_arrived_at')) : null;

                                return $duration(($tB && $tC) ? (int) abs($tC->diffInMinutes($tB)) : null);
                            })
                            ->badge()
                            ->color('info'),
                        TextEntry::make('summary_unloading_duration')
                            ->label('Hạ hàng')
                            ->state(function (Get $get) use ($duration): string {
                                $tC = $get('summary_arrived_at') ? Carbon::parse($get('summary_arrived_at')) : null;
                                $tD = $get('summary_unloaded_at') ? Carbon::parse($get('summary_unloaded_at')) : null;

                                return $duration(($tC && $tD) ? (int) abs($tD->diffInMinutes($tC)) : null);
                            })
                            ->badge()
                            ->color('warning'),
                    ]),
                ]),
            Section::make('Tính giá')
                ->icon(Heroicon::OutlinedCalculator)
                ->iconColor('success')
                ->compact()
                ->schema([
                    $entry('summary_price_code', 'Mã giá', Heroicon::OutlinedHashtag, fn (Get $get): string => $get('price_code') ?: 'Chưa có giá')
                        ->badge()
                        ->color(fn (Get $get): string => $get('price_code') ? 'gray' : 'warning')
                        ->copyable(fn (Get $get): bool => (bool) $get('price_code')),
                    $entry('summary_base_price', 'Đơn giá gốc', Heroicon::OutlinedBanknotes, fn (Get $get): string => $money($number($get('base_price'))))
                        ->helperText($priceNote),
                    $entry('summary_adjusted_price', 'Sau dầu', Heroicon::OutlinedBeaker, fn (Get $get): string => $money($totals($get)['adjusted_price']))
                        ->helperText(fn (Get $get): string => 'Tăng/giảm dầu '.$percent($get('fuel_adjustment_percent')).', làm tròn nghìn'),
                    $entry('summary_waiting_fee', 'Giờ chờ', Heroicon::OutlinedClock, fn (Get $get): string => $money((float) $number($get('waiting_fee'))))
                        ->helperText($waitingNote),
                    $entry('summary_other_fees', 'Phí khác', Heroicon::OutlinedReceiptPercent, fn (Get $get): string => $money($totals($get)['other_fees_total'])),
                    $entry('summary_subtotal', 'Tổng', Heroicon::OutlinedRectangleStack, fn (Get $get): string => $money($totals($get)['subtotal']))
                        ->weight(FontWeight::SemiBold),
                    $entry('summary_vat', 'VAT', Heroicon::OutlinedDocumentText, fn (Get $get): string => $money($totals($get)['vat_amount']))
                        ->helperText(fn (Get $get): string => 'Thuế suất '.$percent($get('vat_percent'))),
                ]),
            Section::make('Thanh toán')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->iconColor('primary')
                ->compact()
                ->schema([
                    $entry('summary_total', 'Tổng thanh toán', Heroicon::OutlinedBanknotes, fn (Get $get): string => $money($totals($get)['total']), 'primary')
                        ->weight(FontWeight::Bold)
                        ->color('primary'),
                ]),
        ];
    }

    /**
     * Lưu các mốc thời gian của đơn/chuyến khi sửa cước.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveTimestamps(int $orderId, array $data): void
    {
        $order = Order::with(['trip', 'deliveryPoints'])->find($orderId);
        if ($order === null) {
            return;
        }

        $trip = $order->trip;

        // 1. Mốc Yêu cầu -> Order::planned_loading_at
        if (array_key_exists('summary_requested_at', $data)) {
            $order->planned_loading_at = filled($data['summary_requested_at'])
                ? Carbon::parse($data['summary_requested_at'])
                : null;
            $order->save();
        }

        $firstDeliveryPoint = $order->deliveryPoints->sortBy('sequence')->first();
        $lastDeliveryPoint = $order->deliveryPoints->sortBy('sequence')->last();

        // 2. Các mốc checkpoint A, B, C, D
        if (array_key_exists('summary_loading_at', $data)) {
            $timeA = filled($data['summary_loading_at']) ? Carbon::parse($data['summary_loading_at']) : null;
            $this->syncTripCheckpoint($order, $trip, CheckpointType::ArrivedPickup, $timeA);
        }

        if (array_key_exists('summary_departed_at', $data)) {
            $timeB = filled($data['summary_departed_at']) ? Carbon::parse($data['summary_departed_at']) : null;
            $this->syncTripCheckpoint($order, $trip, CheckpointType::LeftPickup, $timeB);
        }

        if (array_key_exists('summary_arrived_at', $data)) {
            $timeC = filled($data['summary_arrived_at']) ? Carbon::parse($data['summary_arrived_at']) : null;
            if ($firstDeliveryPoint !== null) {
                $firstDeliveryPoint->update(['arrived_at' => $timeC]);
            }
            $this->syncTripCheckpoint($order, $trip, CheckpointType::ArrivedDelivery, $timeC, $firstDeliveryPoint?->id);
        }

        if (array_key_exists('summary_unloaded_at', $data)) {
            $timeD = filled($data['summary_unloaded_at']) ? Carbon::parse($data['summary_unloaded_at']) : null;
            if ($lastDeliveryPoint !== null) {
                $lastDeliveryPoint->update(['delivered_at' => $timeD]);
            }
            $this->syncTripCheckpoint($order, $trip, CheckpointType::Completed, $timeD, $lastDeliveryPoint?->id);
        }

        if ($trip !== null && $trip->orders()->count() <= 1) {
            $tripUpdates = [];
            $timeA = filled($data['summary_loading_at'] ?? null) ? Carbon::parse($data['summary_loading_at']) : null;
            $timeB = filled($data['summary_departed_at'] ?? null) ? Carbon::parse($data['summary_departed_at']) : null;
            $timeD = filled($data['summary_unloaded_at'] ?? null) ? Carbon::parse($data['summary_unloaded_at']) : null;

            if (array_key_exists('summary_departed_at', $data) || array_key_exists('summary_loading_at', $data)) {
                $tripUpdates['started_at'] = $timeB ?? $timeA;
            }
            if (array_key_exists('summary_unloaded_at', $data)) {
                $tripUpdates['completed_at'] = $timeD;
            }
            if ($tripUpdates !== []) {
                $trip->update($tripUpdates);
            }
        }
    }

    private function syncTripCheckpoint(
        Order $order,
        ?Trip $trip,
        CheckpointType $type,
        ?Carbon $time,
        ?int $deliveryPointId = null
    ): void {
        $checkpoint = TripCheckpoint::query()
            ->where('order_id', $order->id)
            ->where('checkpoint_type', $type)
            ->when($deliveryPointId !== null, fn ($q) => $q->where('delivery_point_id', $deliveryPointId))
            ->first();

        if ($checkpoint === null && $trip !== null) {
            $tripCheckpoint = TripCheckpoint::query()
                ->where('trip_id', $trip->id)
                ->where('checkpoint_type', $type)
                ->whereNull('order_id')
                ->first();

            if ($tripCheckpoint !== null && $trip->orders()->count() <= 1) {
                $checkpoint = $tripCheckpoint;
                $checkpoint->order_id = $order->id;
                if ($deliveryPointId !== null) {
                    $checkpoint->delivery_point_id = $deliveryPointId;
                }
            }
        }

        if ($time === null) {
            $checkpoint?->delete();

            return;
        }

        if ($checkpoint !== null) {
            $checkpoint->occurred_at = $time;
            if ($deliveryPointId !== null && $checkpoint->delivery_point_id === null) {
                $checkpoint->delivery_point_id = $deliveryPointId;
            }
            $checkpoint->save();
        } else {
            TripCheckpoint::create([
                'trip_id' => $trip?->id,
                'order_id' => $order->id,
                'delivery_point_id' => $deliveryPointId,
                'checkpoint_type' => $type,
                'occurred_at' => $time,
                'driver_id' => $trip?->driver_id,
                'shift_id' => $trip?->shift_id,
                'vehicle_id' => $trip?->vehicle_id,
            ]);
        }
    }

    public function exportExcel(): StreamedResponse
    {
        $rows = $this->getAllRows();
        $trips = $this->getFilteredTrips();

        OrderCharge::query()
            ->whereIn('order_id', $rows->pluck('order_id')->filter())
            ->whereNull('exported_at')
            ->update(['exported_at' => now(), 'exported_by' => Auth::id()]);

        $this->bumpDataVersion();

        $tabSuffix = match ($this->activeTab) {
            'HHHK' => 'HHHK',
            'external' => 'Hang-ngoai',
            'empty' => 'Xe-khong-hang',
            default => 'Tong-hop',
        };

        /** @var TripExcelExportService $exportService */
        $exportService = app(TripExcelExportService::class);
        $filename = "Bao-cao-thong-ke-{$tabSuffix}-".now()->format('Ymd-His').'.xlsx';

        return $exportService->exportFromRows($rows, $trips, $filename);
    }
}
