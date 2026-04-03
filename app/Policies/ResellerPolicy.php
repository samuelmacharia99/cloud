<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class ResellerPolicy
{
    /**
     * Only admin can view reseller list.
     */
    public function viewAny(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can view resellers.');
    }

    /**
     * Only admin can view individual reseller.
     */
    public function view(User $user, User $reseller): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can view reseller details.');
    }

    /**
     * Only admin can promote user to reseller.
     */
    public function promote(User $user, User $targetUser): Response
    {
        if (!$user->is_admin) {
            return Response::deny('Only administrators can promote resellers.');
        }

        if ($targetUser->is_reseller) {
            return Response::deny('User is already a reseller.');
        }

        return Response::allow();
    }

    /**
     * Only admin can demote reseller.
     */
    public function demote(User $user, User $targetUser): Response
    {
        if (!$user->is_admin) {
            return Response::deny('Only administrators can demote resellers.');
        }

        if (!$targetUser->is_reseller) {
            return Response::deny('User is not a reseller.');
        }

        return Response::allow();
    }

    /**
     * Reseller can view their own services.
     * Admin can view any services.
     */
    public function viewServices(User $user, User $reseller): Response
    {
        if ($user->is_admin) {
            return Response::allow();
        }

        if ($user->id === $reseller->id && $user->is_reseller) {
            return Response::allow();
        }

        return Response::deny('You can only view your own services.');
    }

    /**
     * Only admin can set reseller pricing tiers.
     */
    public function setPricing(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can set reseller pricing.');
    }
}
