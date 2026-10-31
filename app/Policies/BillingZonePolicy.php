<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\BillingZone;
use Illuminate\Auth\Access\HandlesAuthorization;

class BillingZonePolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:BillingZone');
    }

    public function view(AuthUser $authUser, BillingZone $billingZone): bool
    {
        return $authUser->can('View:BillingZone');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:BillingZone');
    }

    public function update(AuthUser $authUser, BillingZone $billingZone): bool
    {
        return $authUser->can('Update:BillingZone');
    }

    public function delete(AuthUser $authUser, BillingZone $billingZone): bool
    {
        return $authUser->can('Delete:BillingZone');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:BillingZone');
    }

    public function restore(AuthUser $authUser, BillingZone $billingZone): bool
    {
        return $authUser->can('Restore:BillingZone');
    }

    public function forceDelete(AuthUser $authUser, BillingZone $billingZone): bool
    {
        return $authUser->can('ForceDelete:BillingZone');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('ForceDeleteAny:BillingZone');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('RestoreAny:BillingZone');
    }

    public function replicate(AuthUser $authUser, BillingZone $billingZone): bool
    {
        return $authUser->can('Replicate:BillingZone');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('Reorder:BillingZone');
    }

}