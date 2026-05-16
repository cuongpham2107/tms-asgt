<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\VehicleMaintenanceJob;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VehicleMaintenanceJobPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VehicleMaintenanceJob');
    }

    public function view(AuthUser $authUser, VehicleMaintenanceJob $vehicleMaintenanceJob): bool
    {
        return $authUser->can('View:VehicleMaintenanceJob');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VehicleMaintenanceJob');
    }

    public function update(AuthUser $authUser, VehicleMaintenanceJob $vehicleMaintenanceJob): bool
    {
        return $authUser->can('Update:VehicleMaintenanceJob');
    }

    public function delete(AuthUser $authUser, VehicleMaintenanceJob $vehicleMaintenanceJob): bool
    {
        return $authUser->can('Delete:VehicleMaintenanceJob');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VehicleMaintenanceJob');
    }

    public function restore(AuthUser $authUser, VehicleMaintenanceJob $vehicleMaintenanceJob): bool
    {
        return $authUser->can('Restore:VehicleMaintenanceJob');
    }

    public function forceDelete(AuthUser $authUser, VehicleMaintenanceJob $vehicleMaintenanceJob): bool
    {
        return $authUser->can('ForceDelete:VehicleMaintenanceJob');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VehicleMaintenanceJob');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VehicleMaintenanceJob');
    }

    public function replicate(AuthUser $authUser, VehicleMaintenanceJob $vehicleMaintenanceJob): bool
    {
        return $authUser->can('Replicate:VehicleMaintenanceJob');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VehicleMaintenanceJob');
    }
}
