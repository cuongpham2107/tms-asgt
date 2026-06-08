<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmptyKilometer;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class EmptyKilometerPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:EmptyKilometer');
    }

    public function view(AuthUser $authUser, EmptyKilometer $emptyKilometer): bool
    {
        return $authUser->can('View:EmptyKilometer');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:EmptyKilometer');
    }

    public function update(AuthUser $authUser, EmptyKilometer $emptyKilometer): bool
    {
        return $authUser->can('Update:EmptyKilometer');
    }

    public function delete(AuthUser $authUser, EmptyKilometer $emptyKilometer): bool
    {
        return $authUser->can('Delete:EmptyKilometer');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:EmptyKilometer');
    }

    public function restore(AuthUser $authUser, EmptyKilometer $emptyKilometer): bool
    {
        return $authUser->can('Restore:EmptyKilometer');
    }

    public function forceDelete(AuthUser $authUser, EmptyKilometer $emptyKilometer): bool
    {
        return $authUser->can('ForceDelete:EmptyKilometer');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:EmptyKilometer');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:EmptyKilometer');
    }

    public function replicate(AuthUser $authUser, EmptyKilometer $emptyKilometer): bool
    {
        return $authUser->can('Replicate:EmptyKilometer');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:EmptyKilometer');
    }
}
