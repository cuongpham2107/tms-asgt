<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DriverSwap;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DriverSwapPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:DriverSwap');
    }

    public function view(AuthUser $authUser, DriverSwap $driverSwap): bool
    {
        return $authUser->can('View:DriverSwap');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:DriverSwap');
    }

    public function update(AuthUser $authUser, DriverSwap $driverSwap): bool
    {
        return $authUser->can('Update:DriverSwap');
    }

    public function delete(AuthUser $authUser, DriverSwap $driverSwap): bool
    {
        return $authUser->can('Delete:DriverSwap');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:DriverSwap');
    }

    public function restore(AuthUser $authUser, DriverSwap $driverSwap): bool
    {
        return $authUser->can('Restore:DriverSwap');
    }

    public function forceDelete(AuthUser $authUser, DriverSwap $driverSwap): bool
    {
        return $authUser->can('ForceDelete:DriverSwap');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:DriverSwap');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:DriverSwap');
    }

    public function replicate(AuthUser $authUser, DriverSwap $driverSwap): bool
    {
        return $authUser->can('Replicate:DriverSwap');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:DriverSwap');
    }
}
