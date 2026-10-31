<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\WaitingFeeRate;
use Illuminate\Auth\Access\HandlesAuthorization;

class WaitingFeeRatePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:WaitingFeeRate');
    }

    public function view(AuthUser $authUser, WaitingFeeRate $waitingFeeRate): bool
    {
        return $authUser->can('View:WaitingFeeRate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:WaitingFeeRate');
    }

    public function update(AuthUser $authUser, WaitingFeeRate $waitingFeeRate): bool
    {
        return $authUser->can('Update:WaitingFeeRate');
    }

    public function delete(AuthUser $authUser, WaitingFeeRate $waitingFeeRate): bool
    {
        return $authUser->can('Delete:WaitingFeeRate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:WaitingFeeRate');
    }

    public function restore(AuthUser $authUser, WaitingFeeRate $waitingFeeRate): bool
    {
        return $authUser->can('Restore:WaitingFeeRate');
    }

    public function forceDelete(AuthUser $authUser, WaitingFeeRate $waitingFeeRate): bool
    {
        return $authUser->can('ForceDelete:WaitingFeeRate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:WaitingFeeRate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:WaitingFeeRate');
    }

    public function replicate(AuthUser $authUser, WaitingFeeRate $waitingFeeRate): bool
    {
        return $authUser->can('Replicate:WaitingFeeRate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:WaitingFeeRate');
    }

}