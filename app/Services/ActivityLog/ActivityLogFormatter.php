<?php

namespace App\Services\ActivityLog;

use App\Enums\CargoType;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Priority;
use App\Enums\TripStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DriverShift;
use App\Models\Location;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Throwable;

class ActivityLogFormatter
{
    protected static array $cache = [];

    public static function getFieldLabel(string $field): string
    {
        $labels = [
            'status' => 'Trạng thái',
            'vehicle_id' => 'Phương tiện',
            'driver_id' => 'Lái xe',
            'shift_id' => 'Ca làm việc',
            'order_code' => 'Mã đơn hàng',
            'trip_code' => 'Mã chuyến xe',
            'trip_id' => 'Chuyến xe',
            'customer_id' => 'Khách hàng',
            'area_id' => 'Khu vực',
            'parent_order_id' => 'Đơn hàng gốc',
            'created_by' => 'Người tạo',
            'user_id' => 'Tài khoản',
            'total_weight' => 'Tổng trọng lượng',
            'chargeable_weight' => 'Trọng lượng tính phí',
            'total_packages' => 'Tổng số kiện',
            'cargo_name' => 'Tên hàng hóa',
            'cargo_type' => 'Loại hàng hóa',
            'pickup_location_id' => 'Điểm bốc hàng',
            'start_location_id' => 'Điểm xuất phát',
            'end_location_id' => 'Điểm đến',
            'pickup_address' => 'Địa chỉ bốc',
            'pickup_contact' => 'Người liên hệ',
            'pickup_phone' => 'SĐT liên hệ',
            'planned_loading_at' => 'TG dự kiến bốc',
            'started_at' => 'Thời gian bắt đầu',
            'completed_at' => 'Thời gian hoàn thành',
            'cancelled_at' => 'Thời gian hủy',
            'cancel_reason' => 'Lý do hủy',
            'sent_at' => 'Thời gian gửi chuyến',
            'start_km' => 'Km bắt đầu',
            'end_km' => 'Km kết thúc',
            'total_km' => 'Tổng Km',
            'total_km_loaded' => 'Km có tải',
            'total_km_empty' => 'Km không tải',
            'loaded_km' => 'Km tính đơn',
            'is_return_trip' => 'Đơn hàng chiều về',
            'is_empty_run' => 'Chạy rỗng (không tải)',
            'notes' => 'Ghi chú',
            'note' => 'Ghi chú',
            'priority' => 'Độ ưu tiên',
            'type' => 'Loại đơn hàng',
            'trip_sequence' => 'Thứ tự trong chuyến',
        ];

        return $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
    }

    public static function formatValue(string $field, mixed $value, ?string $subjectType = null): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        // 1. Resolve Foreign Key Relationships
        if ($field === 'vehicle_id') {
            return self::resolveRelation(Vehicle::class, $value, fn (Vehicle $v) => $v->plate_number.($v->vehicleType ? ' ('.$v->vehicleType->name.')' : ''));
        }

        if (in_array($field, ['driver_id', 'created_by', 'user_id'])) {
            return self::resolveRelation(User::class, $value, fn (User $u) => $u->name.($u->phone ? ' - '.$u->phone : ''));
        }

        if ($field === 'customer_id') {
            return self::resolveRelation(Customer::class, $value, fn (Customer $c) => $c->name.($c->code ? ' ('.$c->code.')' : ''));
        }

        if (in_array($field, ['pickup_location_id', 'start_location_id', 'end_location_id'])) {
            return self::resolveRelation(Location::class, $value, fn (Location $l) => ($l->code ? '['.$l->code.'] ' : '').$l->name);
        }

        if ($field === 'trip_id') {
            return self::resolveRelation(Trip::class, $value, fn (Trip $t) => $t->trip_code);
        }

        if ($field === 'shift_id') {
            return self::resolveRelation(DriverShift::class, $value, fn (DriverShift $s) => 'Ca #'.$s->id.($s->driver ? ' ('.$s->driver->name.')' : ''));
        }

        if ($field === 'area_id') {
            return self::resolveRelation(Area::class, $value, fn (Area $a) => $a->name.($a->code ? ' ('.$a->code.')' : ''));
        }

        if ($field === 'parent_order_id') {
            return self::resolveRelation(Order::class, $value, fn (Order $o) => $o->order_code);
        }

        // 2. Resolve Enums
        if ($field === 'status') {
            $isTrip = $subjectType === Trip::class || $subjectType === 'App\Models\Trip' || $subjectType === 'trip';
            if ($isTrip) {
                $enum = $value instanceof TripStatus ? $value : TripStatus::tryFrom((string) $value);
                if ($enum) {
                    return $enum->getLabel();
                }
            }

            $orderEnum = $value instanceof OrderStatus ? $value : OrderStatus::tryFrom((string) $value);
            if ($orderEnum) {
                return $orderEnum->getLabel();
            }

            $tripEnum = $value instanceof TripStatus ? $value : TripStatus::tryFrom((string) $value);
            if ($tripEnum) {
                return $tripEnum->getLabel();
            }
        }

        if ($field === 'type') {
            $enum = $value instanceof OrderType ? $value : OrderType::tryFrom((string) $value);
            if ($enum) {
                return $enum->getLabel();
            }
        }

        if ($field === 'priority') {
            $enum = $value instanceof Priority ? $value : Priority::tryFrom((string) $value);
            if ($enum) {
                return $enum->getLabel();
            }
        }

        if ($field === 'cargo_type') {
            $enum = $value instanceof CargoType ? $value : CargoType::tryFrom((string) $value);
            if ($enum) {
                return $enum->getLabel();
            }
        }

        // 3. Resolve Booleans
        if (is_bool($value) || in_array($field, ['is_return_trip', 'is_empty_run'])) {
            return (bool) $value ? 'Có' : 'Không';
        }

        // 4. Resolve Datetime Fields
        if (in_array($field, ['planned_loading_at', 'started_at', 'completed_at', 'cancelled_at', 'sent_at', 'created_at', 'updated_at'])) {
            try {
                return Carbon::parse($value)->format('H:i d/m/Y');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        // 5. Resolve Metric Fields (Weight, Km, Packages)
        if (in_array($field, ['total_weight', 'chargeable_weight']) && is_numeric($value)) {
            return number_format((float) $value, 2, ',', '.').' kg';
        }

        if (in_array($field, ['total_km', 'total_km_loaded', 'total_km_empty', 'start_km', 'end_km', 'loaded_km']) && is_numeric($value)) {
            return number_format((float) $value, 1, ',', '.').' km';
        }

        if ($field === 'total_packages' && is_numeric($value)) {
            return number_format((int) $value, 0, ',', '.').' kiện';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    protected static function resolveRelation(string $modelClass, mixed $id, callable $format): string
    {
        $cacheKey = $modelClass.':'.$id;
        if (! array_key_exists($cacheKey, self::$cache)) {
            try {
                if (method_exists($modelClass, 'withTrashed')) {
                    self::$cache[$cacheKey] = $modelClass::withTrashed()->find($id);
                } else {
                    self::$cache[$cacheKey] = $modelClass::find($id);
                }
            } catch (Throwable) {
                self::$cache[$cacheKey] = null;
            }
        }

        $record = self::$cache[$cacheKey];
        if ($record === null) {
            return '#'.$id;
        }

        try {
            return $format($record);
        } catch (Throwable) {
            return '#'.$id;
        }
    }
}
