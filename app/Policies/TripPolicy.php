<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Trip;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TripPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Trip');
    }

    public function view(AuthUser $authUser, Trip $trip): bool
    {
        return $authUser->can('View:Trip');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Trip');
    }

    public function update(AuthUser $authUser, Trip $trip): bool
    {
        return $authUser->can('Update:Trip');
    }

    public function delete(AuthUser $authUser, Trip $trip): bool
    {
        return $authUser->can('Delete:Trip');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Trip');
    }

    public function restore(AuthUser $authUser, Trip $trip): bool
    {
        return $authUser->can('Restore:Trip');
    }

    public function forceDelete(AuthUser $authUser, Trip $trip): bool
    {
        return $authUser->can('ForceDelete:Trip');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Trip');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Trip');
    }

    public function replicate(AuthUser $authUser, Trip $trip): bool
    {
        return $authUser->can('Replicate:Trip');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Trip');
    }
}
