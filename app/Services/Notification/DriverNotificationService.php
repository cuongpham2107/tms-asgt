<?php

namespace App\Services\Notification;

use App\Models\DriverShift;
use App\Models\Order;
use App\Models\OvertimeRegistration;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FirebaseNotification;
use Throwable;

class DriverNotificationService
{
    protected ?Messaging $messaging = null;

    public function __construct(mixed $messaging = null)
    {
        if ($messaging instanceof Messaging) {
            $this->messaging = $messaging;
        }
    }

    public function setMessaging(?Messaging $messaging): self
    {
        $this->messaging = $messaging;

        return $this;
    }

    /**
     * Lấy instance Firebase Messaging khi cần thiết (Lazy Loading).
     */
    protected function getMessaging(): ?Messaging
    {
        if ($this->messaging !== null) {
            return $this->messaging;
        }

        try {
            if (app()->bound(Messaging::class)) {
                $this->messaging = app(Messaging::class);
            } elseif (app()->bound('firebase.messaging')) {
                $this->messaging = app('firebase.messaging');
            }
        } catch (Throwable $e) {
            Log::debug('Firebase Messaging not initialized: '.$e->getMessage());
        }

        return $this->messaging;
    }

    /**
     * Gửi push notification khi chuyến đi được phát lệnh (SendTripAction).
     */
    public function sendTripDispatched(Trip $trip, int $orderCount = 0): bool
    {
        $driver = $this->resolveDriverForTrip($trip);

        if ($driver === null) {
            Log::info("DriverNotification: Chuyến #{$trip->trip_code} chưa có lái xe để gửi thông báo.");

            return false;
        }

        $countText = $orderCount > 0 ? " với {$orderCount} đơn hàng" : '';
        $title = "Lệnh vận chuyển mới: #{$trip->trip_code}";
        $body = "Bạn có chuyến đi mới #{$trip->trip_code}{$countText} cần thực hiện trong ca.";

        $data = [
            'type' => 'trip_dispatched',
            'trip_id' => (string) $trip->id,
            'trip_code' => (string) $trip->trip_code,
            'order_count' => (string) $orderCount,
        ];

        return $this->sendToDriver($driver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi tạo chuyến không hàng (CreateEmptyRunAction).
     */
    public function sendEmptyRunDispatched(Trip $trip): bool
    {
        $driver = $this->resolveDriverForTrip($trip);

        if ($driver === null) {
            Log::info("DriverNotification: Chuyến không hàng #{$trip->trip_code} chưa có lái xe để gửi thông báo.");

            return false;
        }

        $trip->loadMissing(['startLocation', 'endLocation']);
        $route = '';
        if ($trip->startLocation || $trip->endLocation) {
            $from = $trip->startLocation?->code ?? '—';
            $to = $trip->endLocation?->code ?? '—';
            $route = " ({$from} → {$to})";
        }

        $title = "Chuyến không hàng mới: #{$trip->trip_code}";
        $body = "Bạn có chuyến không hàng mới #{$trip->trip_code}{$route} cần thực hiện trong ca.";

        $data = [
            'type' => 'empty_run_dispatched',
            'trip_id' => (string) $trip->id,
            'trip_code' => (string) $trip->trip_code,
            'is_empty_run' => 'true',
        ];

        return $this->sendToDriver($driver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi một đơn hàng được gán và gửi ngay (createSingleOrder / send_immediately).
     */
    public function sendOrderAssigned(Order $order, ?Trip $trip = null): bool
    {
        if ($trip === null && $order->trip_id !== null) {
            $trip = $order->trip ?? Trip::query()->find($order->trip_id);
        }

        $driver = null;
        if ($trip !== null) {
            $driver = $this->resolveDriverForTrip($trip);
        }

        if ($driver === null && $order->driver_id !== null) {
            $driver = User::query()->find($order->driver_id);
        }

        if ($driver === null) {
            Log::info("DriverNotification: Đơn hàng {$order->order_code} chưa có lái xe để gửi thông báo.");

            return false;
        }

        $tripText = $trip ? " (Chuyến #{$trip->trip_code})" : '';
        $title = "Đơn hàng mới: {$order->order_code}";
        $body = "Bạn vừa nhận được đơn hàng mới {$order->order_code}{$tripText}.";

        $data = [
            'type' => 'order_sent',
            'order_id' => (string) $order->id,
            'order_code' => (string) $order->order_code,
            'trip_id' => (string) ($trip?->id ?? $order->trip_id ?? ''),
            'trip_code' => (string) ($trip?->trip_code ?? $order->trip?->trip_code ?? ''),
        ];

        return $this->sendToDriver($driver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi chuyến đi được bàn giao cho tài xế mới sau khi đảo lái (DriverSwapAction / ReassignDriverAction).
     */
    public function sendTripDriverSwapped(Trip $trip, User $newDriver, ?User $oldDriver = null, float $handoverKm = 0): bool
    {
        $orderCount = $trip->orders()->count();
        $countText = $orderCount > 0 ? " với {$orderCount} đơn hàng" : '';
        $fromText = $oldDriver ? " từ tài xế {$oldDriver->name}" : '';
        $kmText = $handoverKm > 0 ? ' (Km bàn giao: '.number_format($handoverKm, 1, ',', '.').')' : '';

        $title = "Bàn giao chuyến đi: #{$trip->trip_code}";
        $body = "Bạn nhận bàn giao chuyến đi #{$trip->trip_code}{$fromText}{$countText}{$kmText}.";

        $data = [
            'type' => 'trip_driver_swapped',
            'trip_id' => (string) $trip->id,
            'trip_code' => (string) $trip->trip_code,
            'order_count' => (string) $orderCount,
            'handover_km' => (string) $handoverKm,
            'from_driver_id' => (string) ($oldDriver?->id ?? ''),
            'from_driver_name' => (string) ($oldDriver?->name ?? ''),
        ];

        return $this->sendToDriver($newDriver, $title, $body, $data);
    }

    /**
     * Gửi push notification cho tài xế cũ khi chuyến đi đã được chuyển giao cho tài xế mới.
     */
    public function sendTripDriverSwapHandover(Trip $trip, User $oldDriver, ?User $newDriver = null): bool
    {
        $toText = $newDriver ? " cho tài xế {$newDriver->name}" : '';
        $title = "Đã chuyển giao chuyến: #{$trip->trip_code}";
        $body = "Chuyến đi #{$trip->trip_code} đã được chuyển giao{$toText}.";

        $data = [
            'type' => 'trip_driver_swap_handover',
            'trip_id' => (string) $trip->id,
            'trip_code' => (string) $trip->trip_code,
            'to_driver_id' => (string) ($newDriver?->id ?? ''),
            'to_driver_name' => (string) ($newDriver?->name ?? ''),
        ];

        return $this->sendToDriver($oldDriver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi chuyến đi được điều phối lại cho tài xế mới (ReassignTransportAction / TripsTable edit).
     */
    public function sendTripReassigned(Trip $trip, User $newDriver, ?User $oldDriver = null, int $orderCount = 0): bool
    {
        $count = $orderCount > 0 ? $orderCount : $trip->orders()->count();
        $countText = $count > 0 ? " với {$count} đơn hàng" : '';
        $title = "Điều phối chuyến đi: #{$trip->trip_code}";
        $body = "Bạn được gán thực hiện chuyến đi #{$trip->trip_code}{$countText}.";

        $data = [
            'type' => 'trip_reassigned',
            'trip_id' => (string) $trip->id,
            'trip_code' => (string) $trip->trip_code,
            'order_count' => (string) $count,
        ];

        return $this->sendToDriver($newDriver, $title, $body, $data);
    }

    /**
     * Gửi push notification cho tài xế cũ khi chuyến đi bị huỷ gán / chuyển sang lái xe khác.
     */
    public function sendTripUnassigned(Trip $trip, User $oldDriver): bool
    {
        $title = "Hủy gán chuyến đi: #{$trip->trip_code}";
        $body = "Chuyến đi #{$trip->trip_code} đã được điều phối lại cho phương tiện/lái xe khác.";

        $data = [
            'type' => 'trip_unassigned',
            'trip_id' => (string) $trip->id,
            'trip_code' => (string) $trip->trip_code,
        ];

        return $this->sendToDriver($oldDriver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi chuyến đi bị huỷ (CancelTripAction).
     */
    public function sendTripCancelled(Trip $trip, ?string $reason = null): bool
    {
        $driver = $this->resolveDriverForTrip($trip);

        if ($driver === null) {
            Log::info("DriverNotification: Chuyến #{$trip->trip_code} đã huỷ không có lái xe để gửi thông báo.");

            return false;
        }

        $reasonText = filled($reason) ? " Lý do: {$reason}" : '';
        $title = "Huỷ chuyến đi: #{$trip->trip_code}";
        $body = "Chuyến đi #{$trip->trip_code} đã bị huỷ.{$reasonText}";

        $data = [
            'type' => 'trip_cancelled',
            'trip_id' => (string) $trip->id,
            'trip_code' => (string) $trip->trip_code,
            'reason' => (string) ($reason ?? ''),
        ];

        return $this->sendToDriver($driver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi đơn hàng bị huỷ (CancelOrderAction).
     */
    public function sendOrderCancelled(Order $order, ?string $reason = null): bool
    {
        $trip = $order->trip_id ? ($order->trip ?? Trip::query()->find($order->trip_id)) : null;

        $driver = null;
        if ($trip !== null) {
            $driver = $this->resolveDriverForTrip($trip);
        }

        if ($driver === null && $order->driver_id !== null) {
            $driver = User::query()->find($order->driver_id);
        }

        if ($driver === null) {
            Log::info("DriverNotification: Đơn hàng {$order->order_code} đã huỷ không có lái xe để gửi thông báo.");

            return false;
        }

        $reasonText = filled($reason) ? " Lý do: {$reason}" : '';
        $title = "Huỷ đơn hàng: {$order->order_code}";
        $body = "Đơn hàng {$order->order_code} đã bị huỷ.{$reasonText}";

        $data = [
            'type' => 'order_cancelled',
            'order_id' => (string) $order->id,
            'order_code' => (string) $order->order_code,
            'trip_id' => (string) ($trip?->id ?? $order->trip_id ?? ''),
            'reason' => (string) ($reason ?? ''),
        ];

        return $this->sendToDriver($driver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi lệnh vận chuyển đơn hàng bị thu hồi (UnsendOrderAction).
     */
    public function sendOrderRecalled(Order $order): bool
    {
        $trip = $order->trip_id ? ($order->trip ?? Trip::query()->find($order->trip_id)) : null;

        $driver = null;
        if ($trip !== null) {
            $driver = $this->resolveDriverForTrip($trip);
        }

        if ($driver === null && $order->driver_id !== null) {
            $driver = User::query()->find($order->driver_id);
        }

        if ($driver === null) {
            Log::info("DriverNotification: Đơn hàng {$order->order_code} đã thu hồi không có lái xe để gửi thông báo.");

            return false;
        }

        $title = "Thu hồi lệnh đơn hàng: {$order->order_code}";
        $body = "Lệnh vận chuyển cho đơn hàng {$order->order_code} đã được thu hồi.";

        $data = [
            'type' => 'order_recalled',
            'order_id' => (string) $order->id,
            'order_code' => (string) $order->order_code,
            'trip_id' => (string) ($trip?->id ?? $order->trip_id ?? ''),
        ];

        return $this->sendToDriver($driver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi đăng ký tăng cường được xác nhận.
     */
    public function sendOvertimeConfirmed(OvertimeRegistration $registration): bool
    {
        $driver = $registration->driver;

        if ($driver === null) {
            return false;
        }

        $shiftLabel = $registration->shift_type?->getLabel() ?? $registration->shift_type;
        $dateFormatted = $registration->overtime_date ? $registration->overtime_date->format('d/m/Y') : '';

        $title = 'Xác nhận tăng cường';
        $body = "Xác nhận {$driver->name} tăng cường {$shiftLabel} ngày {$dateFormatted}";

        $data = [
            'type' => 'overtime_confirmed',
            'overtime_registration_id' => (string) $registration->id,
            'shift_type' => (string) ($registration->shift_type?->value ?? $registration->shift_type),
            'overtime_date' => $registration->overtime_date ? $registration->overtime_date->format('Y-m-d') : '',
        ];

        return $this->sendToDriver($driver, $title, $body, $data);
    }

    /**
     * Gửi push notification khi đăng ký tăng cường bị từ chối.
     */
    public function sendOvertimeRejected(OvertimeRegistration $registration): bool
    {
        $driver = $registration->driver;

        if ($driver === null) {
            return false;
        }

        $title = 'Đăng ký tăng cường';
        $body = 'Đăng ký không thành công, ca tăng cường đã đủ người';

        $data = [
            'type' => 'overtime_rejected',
            'overtime_registration_id' => (string) $registration->id,
        ];

        return $this->sendToDriver($driver, $title, $body, $data);
    }

    /**
     * Gửi notification trực tiếp tới một User (Lái xe).
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToDriver(User $driver, string $title, string $body, array $data = []): bool
    {
        $token = $driver->fcm_token;

        if (blank($token)) {
            Log::info("DriverNotification: Lái xe {$driver->name} (ID: {$driver->id}) chưa đăng ký FCM token.");

            return false;
        }

        // Hỗ trợ Expo Push Token khi test trên Simulator / Expo Go
        if (str_starts_with($token, 'ExponentPushToken[') || str_starts_with($token, 'ExpoPushToken[')) {
            return $this->sendViaExpoPush($driver, $token, $title, $body, $data);
        }

        return $this->sendViaFcm($driver, $token, $title, $body, $data);
    }

    /**
     * Gửi thông báo qua Expo Push Server (Dành cho iOS Simulator / Expo Go).
     *
     * @param  array<string, mixed>  $data
     */
    protected function sendViaExpoPush(User $driver, string $token, string $title, string $body, array $data = []): bool
    {
        try {
            $response = Http::acceptJson()
                ->timeout(10)
                ->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $token,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'sound' => 'default',
                    'channelId' => 'orders',
                    'priority' => 'high',
                ]);

            if ($response->successful()) {
                Log::info("DriverNotification (Expo/Simulator): Đã gửi thông báo tới lái xe {$driver->name} ({$title})");

                return true;
            }

            Log::warning("DriverNotification (Expo/Simulator): Lỗi gửi Expo push tới {$driver->name}: ".$response->body());

            return false;
        } catch (Throwable $e) {
            Log::warning("DriverNotification (Expo/Simulator): Gửi thông báo tới lái xe {$driver->name} thất bại: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Gửi thông báo qua Firebase Cloud Messaging (FCM v1).
     *
     * @param  array<string, mixed>  $data
     */
    protected function sendViaFcm(User $driver, string $token, string $title, string $body, array $data = []): bool
    {
        $messaging = $this->getMessaging();

        if ($messaging === null) {
            Log::warning("DriverNotification: Không thể kết nối Firebase Messaging để gửi cho {$driver->name} (Vui lòng cấu hình FIREBASE_CREDENTIALS).");

            return false;
        }

        try {
            $stringData = [];
            foreach ($data as $key => $val) {
                $stringData[(string) $key] = is_scalar($val) ? (string) $val : json_encode($val);
            }

            $message = CloudMessage::new()
                ->withToken($token)
                ->withNotification(FirebaseNotification::create($title, $body))
                ->withData($stringData)
                ->withAndroidConfig(AndroidConfig::fromArray([
                    'priority' => 'high',
                    'notification' => [
                        'sound' => 'default',
                        'channel_id' => 'orders',
                    ],
                ]))
                ->withApnsConfig(ApnsConfig::fromArray([
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                        ],
                    ],
                ]));

            $messaging->send($message);

            Log::info("DriverNotification: Đã gửi thông báo tới lái xe {$driver->name} ({$title})");

            return true;
        } catch (Throwable $e) {
            Log::warning("DriverNotification: Gửi thông báo tới lái xe {$driver->name} thất bại: ".$e->getMessage());

            // Nếu token không hợp lệ hoặc đã hết hạn, xóa token cũ
            if (str_contains($e->getMessage(), 'Requested entity was not found') ||
                str_contains($e->getMessage(), 'Unregistered') ||
                str_contains($e->getMessage(), 'InvalidArgumentException')) {
                $driver->update([
                    'fcm_token' => null,
                    'fcm_token_updated_at' => null,
                ]);
            }

            return false;
        }
    }

    /**
     * Tìm lái xe tương ứng với chuyến đi.
     */
    protected function resolveDriverForTrip(Trip $trip): ?User
    {
        if ($trip->driver !== null) {
            return $trip->driver;
        }

        if ($trip->driver_id !== null) {
            $driver = User::query()->find($trip->driver_id);
            if ($driver !== null) {
                return $driver;
            }
        }

        if ($trip->shift !== null && $trip->shift->driver !== null) {
            return $trip->shift->driver;
        }

        if ($trip->shift_id !== null) {
            $shift = $trip->shift ?? DriverShift::query()->find($trip->shift_id);
            if ($shift?->driver !== null) {
                return $shift->driver;
            }
        }

        if ($trip->vehicle !== null && $trip->vehicle->current_driver_id !== null) {
            return User::query()->find($trip->vehicle->current_driver_id);
        }

        if ($trip->vehicle_id !== null) {
            $vehicle = Vehicle::query()->find($trip->vehicle_id);
            if ($vehicle?->current_driver_id !== null) {
                return User::query()->find($vehicle->current_driver_id);
            }
        }

        return null;
    }
}
