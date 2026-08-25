<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OnDutyLocation;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'email',
    'password',
    'cccd',
    'cccd_issue_date',
    'certificates',
    'license_number',
    'license_expiry_date',
    'license_class',
    'license_issue_date',
    'phone',
    'address',
    'date_of_birth',
    'station',
    'avatar',
    'license_image',
    'aviation_security_cert_number',
    'aviation_security_cert_issue_date',
    'aviation_security_cert_expiry_date',
    'aviation_security_cert_image',
    'dangerous_goods_cert_number',
    'dangerous_goods_cert_issue_date',
    'dangerous_goods_cert_expiry_date',
    'dangerous_goods_cert_image',
    'is_active',
    'email_verified_at',
    'fcm_token',
    'fcm_token_updated_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    use HasRoles;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'station' => OnDutyLocation::class,
            'date_of_birth' => 'date',
            'cccd_issue_date' => 'date',
            'license_issue_date' => 'date',
            'license_expiry_date' => 'date',
            'aviation_security_cert_issue_date' => 'date',
            'aviation_security_cert_expiry_date' => 'date',
            'dangerous_goods_cert_issue_date' => 'date',
            'dangerous_goods_cert_expiry_date' => 'date',
            'fcm_token_updated_at' => 'datetime',
        ];
    }

    /**
     * @return array{status: 'valid'|'expiring_soon'|'expired'|'missing', label: string, color: string, days_remaining: ?int, formatted_date: ?string}
     */
    public static function calculateExpiryStatus(?CarbonInterface $expiryDate, string $certName = 'Chứng chỉ'): array
    {
        if ($expiryDate === null) {
            return [
                'status' => 'missing',
                'label' => 'Chưa cập nhật',
                'color' => 'gray',
                'days_remaining' => null,
                'formatted_date' => null,
            ];
        }

        $today = now()->startOfDay();
        $expiry = $expiryDate->copy()->startOfDay();
        $days = (int) $today->diffInDays($expiry, false);

        if ($days < 0) {
            $overdueDays = abs($days);

            return [
                'status' => 'expired',
                'label' => "Đã hết hạn ({$overdueDays} ngày trước)",
                'color' => 'danger',
                'days_remaining' => $days,
                'formatted_date' => $expiryDate->format('d/m/Y'),
            ];
        }

        if ($days <= 30) {
            return [
                'status' => 'expiring_soon',
                'label' => "Sắp hết hạn (còn {$days} ngày)",
                'color' => 'warning',
                'days_remaining' => $days,
                'formatted_date' => $expiryDate->format('d/m/Y'),
            ];
        }

        return [
            'status' => 'valid',
            'label' => "Còn hạn ({$expiryDate->format('d/m/Y')})",
            'color' => 'success',
            'days_remaining' => $days,
            'formatted_date' => $expiryDate->format('d/m/Y'),
        ];
    }

    /**
     * @return array{status: 'valid'|'expiring_soon'|'expired'|'missing', label: string, color: string, days_remaining: ?int, formatted_date: ?string}
     */
    public function getLicenseExpiryStatus(): array
    {
        return self::calculateExpiryStatus($this->license_expiry_date, 'GPLX');
    }

    /**
     * @return array{status: 'valid'|'expiring_soon'|'expired'|'missing', label: string, color: string, days_remaining: ?int, formatted_date: ?string}
     */
    public function getAviationSecurityCertStatus(): array
    {
        return self::calculateExpiryStatus($this->aviation_security_cert_expiry_date, 'ANHK');
    }

    /**
     * @return array{status: 'valid'|'expiring_soon'|'expired'|'missing', label: string, color: string, days_remaining: ?int, formatted_date: ?string}
     */
    public function getDangerousGoodsCertStatus(): array
    {
        return self::calculateExpiryStatus($this->dangerous_goods_cert_expiry_date, 'Hàng nguy hiểm');
    }

    public function hasExpiredCertificates(): bool
    {
        return in_array('expired', [
            $this->getLicenseExpiryStatus()['status'],
            $this->getAviationSecurityCertStatus()['status'],
            $this->getDangerousGoodsCertStatus()['status'],
        ], true);
    }

    public function hasExpiringSoonCertificates(): bool
    {
        return in_array('expiring_soon', [
            $this->getLicenseExpiryStatus()['status'],
            $this->getAviationSecurityCertStatus()['status'],
            $this->getDangerousGoodsCertStatus()['status'],
        ], true);
    }

    public function vehiclesAsDriver(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'current_driver_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class, 'driver_id');
    }

    public function orders(): HasManyThrough
    {
        return $this->hasManyThrough(Order::class, Trip::class, 'driver_id', 'trip_id');
    }

    public function createdOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'created_by');
    }

    public function driverShifts(): HasMany
    {
        return $this->hasMany(DriverShift::class, 'driver_id');
    }

    public function tripCheckpoints(): HasMany
    {
        return $this->hasMany(TripCheckpoint::class, 'driver_id');
    }

    public function vehicleDocuments(): HasMany
    {
        return $this->hasMany(VehicleDocument::class, 'created_by');
    }

    public function maintenanceJobs(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceJob::class, 'created_by');
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(VehicleMaintenanceSchedule::class, 'created_by');
    }

    public function driverSwapsFrom(): HasMany
    {
        return $this->hasMany(DriverSwap::class, 'from_driver_id');
    }

    public function driverSwapsTo(): HasMany
    {
        return $this->hasMany(DriverSwap::class, 'to_driver_id');
    }

    public function driverSwapsCreated(): HasMany
    {
        return $this->hasMany(DriverSwap::class, 'created_by');
    }

    public function orderTemplates(): HasMany
    {
        return $this->hasMany(OrderTemplate::class, 'created_by');
    }
}
