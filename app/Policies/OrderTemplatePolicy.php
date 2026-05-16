<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderTemplate;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OrderTemplatePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OrderTemplate');
    }

    public function view(AuthUser $authUser, OrderTemplate $orderTemplate): bool
    {
        return $authUser->can('View:OrderTemplate');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OrderTemplate');
    }

    public function update(AuthUser $authUser, OrderTemplate $orderTemplate): bool
    {
        return $authUser->can('Update:OrderTemplate');
    }

    public function delete(AuthUser $authUser, OrderTemplate $orderTemplate): bool
    {
        return $authUser->can('Delete:OrderTemplate');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OrderTemplate');
    }

    public function restore(AuthUser $authUser, OrderTemplate $orderTemplate): bool
    {
        return $authUser->can('Restore:OrderTemplate');
    }

    public function forceDelete(AuthUser $authUser, OrderTemplate $orderTemplate): bool
    {
        return $authUser->can('ForceDelete:OrderTemplate');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OrderTemplate');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OrderTemplate');
    }

    public function replicate(AuthUser $authUser, OrderTemplate $orderTemplate): bool
    {
        return $authUser->can('Replicate:OrderTemplate');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:OrderTemplate');
    }
}
