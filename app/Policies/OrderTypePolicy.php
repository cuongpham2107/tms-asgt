<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OrderTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OrderType');
    }

    public function view(AuthUser $authUser, OrderType $orderType): bool
    {
        return $authUser->can('View:OrderType');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OrderType');
    }

    public function update(AuthUser $authUser, OrderType $orderType): bool
    {
        return $authUser->can('Update:OrderType');
    }

    public function delete(AuthUser $authUser, OrderType $orderType): bool
    {
        return $authUser->can('Delete:OrderType');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OrderType');
    }

    public function restore(AuthUser $authUser, OrderType $orderType): bool
    {
        return $authUser->can('Restore:OrderType');
    }

    public function forceDelete(AuthUser $authUser, OrderType $orderType): bool
    {
        return $authUser->can('ForceDelete:OrderType');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OrderType');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OrderType');
    }

    public function replicate(AuthUser $authUser, OrderType $orderType): bool
    {
        return $authUser->can('Replicate:OrderType');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:OrderType');
    }
}
