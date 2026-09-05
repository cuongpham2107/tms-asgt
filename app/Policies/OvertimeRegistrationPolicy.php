<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OvertimeRegistration;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class OvertimeRegistrationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:OvertimeRegistration');
    }

    public function view(AuthUser $authUser, OvertimeRegistration $overtimeRegistration): bool
    {
        return $authUser->can('View:OvertimeRegistration');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:OvertimeRegistration');
    }

    public function update(AuthUser $authUser, OvertimeRegistration $overtimeRegistration): bool
    {
        return $authUser->can('Update:OvertimeRegistration');
    }

    public function delete(AuthUser $authUser, OvertimeRegistration $overtimeRegistration): bool
    {
        return $authUser->can('Delete:OvertimeRegistration');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:OvertimeRegistration');
    }

    public function restore(AuthUser $authUser, OvertimeRegistration $overtimeRegistration): bool
    {
        return $authUser->can('Restore:OvertimeRegistration');
    }

    public function forceDelete(AuthUser $authUser, OvertimeRegistration $overtimeRegistration): bool
    {
        return $authUser->can('ForceDelete:OvertimeRegistration');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:OvertimeRegistration');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:OvertimeRegistration');
    }
}
