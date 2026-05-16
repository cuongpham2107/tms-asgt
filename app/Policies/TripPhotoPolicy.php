<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TripPhoto;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TripPhotoPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TripPhoto');
    }

    public function view(AuthUser $authUser, TripPhoto $tripPhoto): bool
    {
        return $authUser->can('View:TripPhoto');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TripPhoto');
    }

    public function update(AuthUser $authUser, TripPhoto $tripPhoto): bool
    {
        return $authUser->can('Update:TripPhoto');
    }

    public function delete(AuthUser $authUser, TripPhoto $tripPhoto): bool
    {
        return $authUser->can('Delete:TripPhoto');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:TripPhoto');
    }

    public function restore(AuthUser $authUser, TripPhoto $tripPhoto): bool
    {
        return $authUser->can('Restore:TripPhoto');
    }

    public function forceDelete(AuthUser $authUser, TripPhoto $tripPhoto): bool
    {
        return $authUser->can('ForceDelete:TripPhoto');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TripPhoto');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TripPhoto');
    }

    public function replicate(AuthUser $authUser, TripPhoto $tripPhoto): bool
    {
        return $authUser->can('Replicate:TripPhoto');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TripPhoto');
    }
}
