<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TripCheckpoint;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class TripCheckpointPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:TripCheckpoint');
    }

    public function view(AuthUser $authUser, TripCheckpoint $tripCheckpoint): bool
    {
        return $authUser->can('View:TripCheckpoint');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:TripCheckpoint');
    }

    public function update(AuthUser $authUser, TripCheckpoint $tripCheckpoint): bool
    {
        return $authUser->can('Update:TripCheckpoint');
    }

    public function delete(AuthUser $authUser, TripCheckpoint $tripCheckpoint): bool
    {
        return $authUser->can('Delete:TripCheckpoint');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:TripCheckpoint');
    }

    public function restore(AuthUser $authUser, TripCheckpoint $tripCheckpoint): bool
    {
        return $authUser->can('Restore:TripCheckpoint');
    }

    public function forceDelete(AuthUser $authUser, TripCheckpoint $tripCheckpoint): bool
    {
        return $authUser->can('ForceDelete:TripCheckpoint');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:TripCheckpoint');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:TripCheckpoint');
    }

    public function replicate(AuthUser $authUser, TripCheckpoint $tripCheckpoint): bool
    {
        return $authUser->can('Replicate:TripCheckpoint');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:TripCheckpoint');
    }
}
