<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FreightRate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class FreightRatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FreightRate');
    }

    public function view(AuthUser $authUser, FreightRate $freightRate): bool
    {
        return $authUser->can('View:FreightRate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FreightRate');
    }

    public function update(AuthUser $authUser, FreightRate $freightRate): bool
    {
        return $authUser->can('Update:FreightRate');
    }

    public function delete(AuthUser $authUser, FreightRate $freightRate): bool
    {
        return $authUser->can('Delete:FreightRate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FreightRate');
    }

    public function restore(AuthUser $authUser, FreightRate $freightRate): bool
    {
        return $authUser->can('Restore:FreightRate');
    }

    public function forceDelete(AuthUser $authUser, FreightRate $freightRate): bool
    {
        return $authUser->can('ForceDelete:FreightRate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FreightRate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FreightRate');
    }

    public function replicate(AuthUser $authUser, FreightRate $freightRate): bool
    {
        return $authUser->can('Replicate:FreightRate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FreightRate');
    }
}
