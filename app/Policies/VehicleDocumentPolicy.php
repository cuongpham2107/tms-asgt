<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\VehicleDocument;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VehicleDocumentPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:VehicleDocument');
    }

    public function view(AuthUser $authUser, VehicleDocument $vehicleDocument): bool
    {
        return $authUser->can('View:VehicleDocument');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:VehicleDocument');
    }

    public function update(AuthUser $authUser, VehicleDocument $vehicleDocument): bool
    {
        return $authUser->can('Update:VehicleDocument');
    }

    public function delete(AuthUser $authUser, VehicleDocument $vehicleDocument): bool
    {
        return $authUser->can('Delete:VehicleDocument');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:VehicleDocument');
    }

    public function restore(AuthUser $authUser, VehicleDocument $vehicleDocument): bool
    {
        return $authUser->can('Restore:VehicleDocument');
    }

    public function forceDelete(AuthUser $authUser, VehicleDocument $vehicleDocument): bool
    {
        return $authUser->can('ForceDelete:VehicleDocument');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:VehicleDocument');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:VehicleDocument');
    }

    public function replicate(AuthUser $authUser, VehicleDocument $vehicleDocument): bool
    {
        return $authUser->can('Replicate:VehicleDocument');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:VehicleDocument');
    }
}
