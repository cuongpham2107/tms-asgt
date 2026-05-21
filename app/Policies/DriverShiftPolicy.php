<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DriverShift;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DriverShiftPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DriverShift');
    }

    public function view(AuthUser $authUser, DriverShift $driverShift): bool
    {
        return $authUser->can('View:DriverShift');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DriverShift');
    }

    public function update(AuthUser $authUser, DriverShift $driverShift): bool
    {
        return $authUser->can('Update:DriverShift');
    }

    public function delete(AuthUser $authUser, DriverShift $driverShift): bool
    {
        return $authUser->can('Delete:DriverShift');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DriverShift');
    }

    public function restore(AuthUser $authUser, DriverShift $driverShift): bool
    {
        return $authUser->can('Restore:DriverShift');
    }

    public function forceDelete(AuthUser $authUser, DriverShift $driverShift): bool
    {
        return $authUser->can('ForceDelete:DriverShift');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DriverShift');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DriverShift');
    }

    public function replicate(AuthUser $authUser, DriverShift $driverShift): bool
    {
        return $authUser->can('Replicate:DriverShift');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DriverShift');
    }
}
