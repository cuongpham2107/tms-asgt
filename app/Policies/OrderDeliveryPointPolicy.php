<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderDeliveryPoint;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OrderDeliveryPointPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OrderDeliveryPoint');
    }

    public function view(AuthUser $authUser, OrderDeliveryPoint $orderDeliveryPoint): bool
    {
        return $authUser->can('View:OrderDeliveryPoint');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OrderDeliveryPoint');
    }

    public function update(AuthUser $authUser, OrderDeliveryPoint $orderDeliveryPoint): bool
    {
        return $authUser->can('Update:OrderDeliveryPoint');
    }

    public function delete(AuthUser $authUser, OrderDeliveryPoint $orderDeliveryPoint): bool
    {
        return $authUser->can('Delete:OrderDeliveryPoint');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OrderDeliveryPoint');
    }

    public function restore(AuthUser $authUser, OrderDeliveryPoint $orderDeliveryPoint): bool
    {
        return $authUser->can('Restore:OrderDeliveryPoint');
    }

    public function forceDelete(AuthUser $authUser, OrderDeliveryPoint $orderDeliveryPoint): bool
    {
        return $authUser->can('ForceDelete:OrderDeliveryPoint');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OrderDeliveryPoint');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OrderDeliveryPoint');
    }

    public function replicate(AuthUser $authUser, OrderDeliveryPoint $orderDeliveryPoint): bool
    {
        return $authUser->can('Replicate:OrderDeliveryPoint');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:OrderDeliveryPoint');
    }
}
