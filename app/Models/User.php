<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'cccd', 'cccd_issue_date', 'certificates', 'license_number', 'license_expiry_date', 'license_class', 'phone', 'address', 'date_of_birth'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
        ];
    }

    public function vehiclesAsDriver(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'current_driver_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'driver_id');
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
