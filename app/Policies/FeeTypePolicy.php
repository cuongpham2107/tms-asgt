<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\FeeType;
use Illuminate\Auth\Access\HandlesAuthorization;

class FeeTypePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:FeeType');
    }

    public function view(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('View:FeeType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:FeeType');
    }

    public function update(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('Update:FeeType');
    }

    public function delete(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('Delete:FeeType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:FeeType');
    }

    public function restore(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('Restore:FeeType');
    }

    public function forceDelete(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('ForceDelete:FeeType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:FeeType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:FeeType');
    }

    public function replicate(AuthUser $authUser, FeeType $feeType): bool
    {
        return $authUser->can('Replicate:FeeType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:FeeType');
    }

}