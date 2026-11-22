<?php

namespace App\Console\Commands;

use App\Filament\Resources\Trips\TripResource;
use App\Models\Trip;
use App\Models\User;
use App\Services\Trip\TripKmAuditService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

#[Signature('trips:audit-km {--days=2 : Số ngày gần nhất cần rà (tính cả hôm nay)} {--dry-run : Chỉ in danh sách, không gửi thông báo}')]
#[Description('Rà km chuyến nhập sai và gửi thông báo có nút "Sửa chuyến" cho tài khoản có quyền nhận cảnh báo km')]
class AuditTripKmCommand extends Command
{
    /** Quyền nhận thông báo; tên cố định, gán cho vai trò trong màn Vai trò. */
    public const RECEIVE_PERMISSION = 'Receive:TripKmAuditNotification';

    /** Mỗi lần chạy gửi tối đa chừng này thông báo, tránh ngập hộp thông báo. */
    public const MAX_NOTIFICATIONS = 30;

    /** Lỗi đã báo thì không báo lại trong khoảng này (rà lùi 2 ngày nên một chuyến có thể được rà 2 lần). */
    public const REMEMBER_DAYS = 7;

    public function handle(TripKmAuditService $service): int
    {
        $days = max(1, (int) $this->option('days'));
        $issues = collect($service->audit(now()->subDays($days - 1)->startOfDay(), now()));

        if ($issues->isEmpty()) {
            $this->info('Không phát hiện chuyến nào nhập km bất thường.');

            return self::SUCCESS;
        }

        $this->table(
            ['Chuyến', 'Xe', 'Nguồn', 'Lỗi', 'Chi tiết'],
            $issues->map(fn (array $issue): array => [
                $issue['trip']->trip_code,
                $issue['trip']->vehicle?->plate_number,
                $this->sourceLabel($issue),
                $issue['issue'],
                $issue['detail'],
            ])->all(),
        );

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $recipients = User::query()->activeWithPermission(self::RECEIVE_PERMISSION)->get();

        if ($recipients->isEmpty()) {
            $this->warn('Không có tài khoản đang hoạt động nào có quyền nhận cảnh báo km.');

            return self::SUCCESS;
        }

        $sent = $issues
            ->filter(fn (array $issue): bool => Cache::add($this->issueKey($issue), true, now()->addDays(self::REMEMBER_DAYS)))
            ->take(self::MAX_NOTIFICATIONS)
            ->each(fn (array $issue) => $this->notify($issue, $recipients))
            ->count();

        $this->info("Đã gửi {$sent} thông báo mới cho {$recipients->count()} người.");

        return self::SUCCESS;
    }

    /**
     * @param  array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}  $issue
     * @param  Collection<int, User>  $recipients
     */
    private function notify(array $issue, Collection $recipients): void
    {
        $trip = $issue['trip'];
        $notification = Notification::make()
            ->title('Km nghi sai: '.$trip->trip_code.' · '.($trip->vehicle?->plate_number ?? 'chưa có xe'))
            ->body($issue['issue'].'. '.$issue['detail'].'. Tài xế: '.($trip->driver?->name ?? '—').'. '.$this->sourceLabel($issue).'.')
            ->icon('heroicon-o-exclamation-triangle')
            ->warning()
            ->actions([
                Action::make('edit_trip')
                    ->label('Sửa chuyến')
                    ->button()
                    ->url(TripResource::getEditTripUrl($trip))
                    ->markAsRead()
                    ->close()
                    ->alpineClickHandler("\$dispatch('close-modal', { id: 'database-notifications' }); markAsRead(); close(true);"),
            ]);

        $notification->sendToDatabase($recipients, isEventDispatched: true);

        try {
            $notification->broadcast($recipients);
        } catch (Throwable) {
            // Không có máy chủ broadcast thì vẫn còn thông báo trong database.
        }
    }

    /**
     * @param  array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}  $issue
     */
    private function sourceLabel(array $issue): string
    {
        return $issue['source'] === TripKmAuditService::SOURCE_STAFF
            ? 'Do điều phối sửa ('.$issue['editor'].')'
            : 'Do tài xế nhập trên app';
    }

    /**
     * @param  array{trip: Trip, source: string, issue: string, detail: string, editor: ?string}  $issue
     */
    private function issueKey(array $issue): string
    {
        return 'trip-km-audit:'.md5($issue['trip']->getKey().'|'.$issue['issue'].'|'.$issue['detail']);
    }
}
