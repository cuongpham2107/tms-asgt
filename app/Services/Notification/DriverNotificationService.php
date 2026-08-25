<?php

namespace App\Services\Notification;

use App\Models\DriverShift;
use App\Models\Order;
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
            'trip_id' => (string) ($trip?->id ?? ''),
            'trip_code' => (string) ($trip?->trip_code ?? ''),
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
                ->timeout(5)
                ->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $token,
                    'title' => $title,
                    'body' => $body,
                    'data' => $data,
                    'sound' => 'default',
                    'channelId' => 'orders',
                    'priority' => 'high',
                    'ttl' => 0,
                    '_displayInForeground' => true,
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
                    'ttl' => '0s',
                    'notification' => [
                        'sound' => 'default',
                        'channel_id' => 'orders',
                        'priority' => 'max',
                        'default_vibrate_timings' => true,
                        'default_sound' => true,
                    ],
                ]))
                ->withApnsConfig(ApnsConfig::fromArray([
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert',
                    ],
                    'payload' => [
                        'aps' => [
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                            'sound' => 'default',
                            'badge' => 1,
                            'content-available' => 1,
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
