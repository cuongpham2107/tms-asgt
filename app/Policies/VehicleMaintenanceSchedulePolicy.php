<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\VehicleMaintenanceSchedule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VehicleMaintenanceSchedulePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VehicleMaintenanceSchedule');
    }

    public function view(AuthUser $authUser, VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): bool
    {
        return $authUser->can('View:VehicleMaintenanceSchedule');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VehicleMaintenanceSchedule');
    }

    public function update(AuthUser $authUser, VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): bool
    {
        return $authUser->can('Update:VehicleMaintenanceSchedule');
    }

    public function delete(AuthUser $authUser, VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): bool
    {
        return $authUser->can('Delete:VehicleMaintenanceSchedule');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VehicleMaintenanceSchedule');
    }

    public function restore(AuthUser $authUser, VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): bool
    {
        return $authUser->can('Restore:VehicleMaintenanceSchedule');
    }

    public function forceDelete(AuthUser $authUser, VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): bool
    {
        return $authUser->can('ForceDelete:VehicleMaintenanceSchedule');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VehicleMaintenanceSchedule');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VehicleMaintenanceSchedule');
    }

    public function replicate(AuthUser $authUser, VehicleMaintenanceSchedule $vehicleMaintenanceSchedule): bool
    {
        return $authUser->can('Replicate:VehicleMaintenanceSchedule');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VehicleMaintenanceSchedule');
    }
}
