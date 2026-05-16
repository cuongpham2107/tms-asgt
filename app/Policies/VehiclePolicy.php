<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Vehicle;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VehiclePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Vehicle');
    }

    public function view(AuthUser $authUser, Vehicle $vehicle): bool
    {
        return $authUser->can('View:Vehicle');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Vehicle');
    }

    public function update(AuthUser $authUser, Vehicle $vehicle): bool
    {
        return $authUser->can('Update:Vehicle');
    }

    public function delete(AuthUser $authUser, Vehicle $vehicle): bool
    {
        return $authUser->can('Delete:Vehicle');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Vehicle');
    }

    public function restore(AuthUser $authUser, Vehicle $vehicle): bool
    {
        return $authUser->can('Restore:Vehicle');
    }

    public function forceDelete(AuthUser $authUser, Vehicle $vehicle): bool
    {
        return $authUser->can('ForceDelete:Vehicle');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Vehicle');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Vehicle');
    }

    public function replicate(AuthUser $authUser, Vehicle $vehicle): bool
    {
        return $authUser->can('Replicate:Vehicle');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Vehicle');
    }
}
