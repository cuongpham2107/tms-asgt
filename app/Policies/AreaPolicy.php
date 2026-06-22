<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Area;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AreaPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:Area');
    }

    public function view(AuthUser $authUser, Area $area): bool
    {
        return $authUser->can('View:Area');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:Area');
    }

    public function update(AuthUser $authUser, Area $area): bool
    {
        return $authUser->can('Update:Area');
    }

    public function delete(AuthUser $authUser, Area $area): bool
    {
        return $authUser->can('Delete:Area');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:Area');
    }

    public function restore(AuthUser $authUser, Area $area): bool
    {
        return $authUser->can('Restore:Area');
    }

    public function forceDelete(AuthUser $authUser, Area $area): bool
    {
        return $authUser->can('ForceDelete:Area');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:Area');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:Area');
    }

    public function replicate(AuthUser $authUser, Area $area): bool
    {
        return $authUser->can('Replicate:Area');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:Area');
    }
}
