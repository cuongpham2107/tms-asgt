<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ShiftVehicle;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ShiftVehiclePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:ShiftVehicle');
    }

    public function view(AuthUser $authUser, ShiftVehicle $shiftVehicle): bool
    {
        return $authUser->can('View:ShiftVehicle');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:ShiftVehicle');
    }

    public function update(AuthUser $authUser, ShiftVehicle $shiftVehicle): bool
    {
        return $authUser->can('Update:ShiftVehicle');
    }

    public function delete(AuthUser $authUser, ShiftVehicle $shiftVehicle): bool
    {
        return $authUser->can('Delete:ShiftVehicle');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:ShiftVehicle');
    }

    public function restore(AuthUser $authUser, ShiftVehicle $shiftVehicle): bool
    {
        return $authUser->can('Restore:ShiftVehicle');
    }

    public function forceDelete(AuthUser $authUser, ShiftVehicle $shiftVehicle): bool
    {
        return $authUser->can('ForceDelete:ShiftVehicle');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:ShiftVehicle');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:ShiftVehicle');
    }

    public function replicate(AuthUser $authUser, ShiftVehicle $shiftVehicle): bool
    {
        return $authUser->can('Replicate:ShiftVehicle');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:ShiftVehicle');
    }
}
