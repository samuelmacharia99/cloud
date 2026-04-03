<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Service;
use Illuminate\Auth\Access\Response;

class ServicePolicy
{
    /**
     * Admin can view all services. Customer can view own.
     */
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    /**
     * Customer can view their own service. Admin can view any.
     */
    public function view(User $user, Service $service): Response
    {
        return $user->is_admin || $user->id === $service->user_id
            ? Response::allow()
            : Response::deny('You can only view your own services.');
    }

    /**
     * Only admin can create services.
     */
    public function create(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can create services.');
    }

    /**
     * Only admin can update service details.
     */
    public function update(User $user, Service $service): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can update services.');
    }

    /**
     * Only admin can delete services.
     */
    public function delete(User $user, Service $service): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can delete services.');
    }

    /**
     * Only admin can provision services.
     */
    public function provision(User $user, Service $service): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can provision services.');
    }

    /**
     * Only admin can suspend services.
     */
    public function suspend(User $user, Service $service): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can suspend services.');
    }

    /**
     * Only admin can unsuspend services.
     */
    public function unsuspend(User $user, Service $service): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can unsuspend services.');
    }

    /**
     * Only admin can terminate services.
     */
    public function terminate(User $user, Service $service): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can terminate services.');
    }

    /**
     * Only admin can refresh service status.
     */
    public function refreshStatus(User $user, Service $service): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can refresh service status.');
    }
}
