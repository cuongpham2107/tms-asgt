<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderCategory;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OrderCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OrderCategory');
    }

    public function view(AuthUser $authUser, OrderCategory $orderCategory): bool
    {
        return $authUser->can('View:OrderCategory');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OrderCategory');
    }

    public function update(AuthUser $authUser, OrderCategory $orderCategory): bool
    {
        return $authUser->can('Update:OrderCategory');
    }

    public function delete(AuthUser $authUser, OrderCategory $orderCategory): bool
    {
        return $authUser->can('Delete:OrderCategory');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OrderCategory');
    }

    public function restore(AuthUser $authUser, OrderCategory $orderCategory): bool
    {
        return $authUser->can('Restore:OrderCategory');
    }

    public function forceDelete(AuthUser $authUser, OrderCategory $orderCategory): bool
    {
        return $authUser->can('ForceDelete:OrderCategory');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OrderCategory');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OrderCategory');
    }

    public function replicate(AuthUser $authUser, OrderCategory $orderCategory): bool
    {
        return $authUser->can('Replicate:OrderCategory');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:OrderCategory');
    }
}
