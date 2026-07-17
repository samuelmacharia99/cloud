<?php

namespace App\Policies;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Models\User;
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
     * Customer can rename their own service for personal reference.
     */
    public function rename(User $user, Service $service): Response
    {
        return $user->is_admin || $user->id === $service->user_id
            ? Response::allow()
            : Response::deny('You can only rename your own services.');
    }

    /**
     * Customer can open a one-time WordPress admin SSO link for their own service.
     */
    public function wordpressAdminLogin(User $user, Service $service): Response
    {
        return $user->is_admin || $user->id === $service->user_id
            ? Response::allow()
            : Response::deny('You can only open WordPress admin for your own services.');
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

    /**
     * Only admin can transfer services between customers.
     */
    public function transfer(User $user, Service $service): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can transfer services.');
    }

    /**
     * Customer can manage container lifecycle for their own services. Admin can manage any.
     */
    public function manageContainer(User $user, Service $service): Response
    {
        if ($service->product?->type !== 'container_hosting') {
            return Response::deny('This action is only available for container services.');
        }

        return $user->is_admin || $user->id === $service->user_id
            ? Response::allow()
            : Response::deny('You can only manage your own container services.');
    }

    /**
     * Customer can manage files for their own container services. Admin can manage any.
     */
    public function manageFiles(User $user, Service $service): Response
    {
        return $user->is_admin || $user->id === $service->user_id
            ? Response::allow()
            : Response::deny('You can only manage files for your own services.');
    }

    /**
     * Customer can access terminal for their own container services. Admin can access any.
     */
    public function accessTerminal(User $user, Service $service): Response
    {
        return $user->is_admin || $user->id === $service->user_id
            ? Response::allow()
            : Response::deny('You can only access terminal for your own services.');
    }

    /**
     * Customer can manage DirectAdmin hosting panel for their own active shared hosting services.
     */
    public function manageHostingPanel(User $user, Service $service): Response
    {
        if (! $service->isSharedHosting()) {
            return Response::deny('This service does not include a hosting control panel.');
        }

        if ($service->status !== ServiceStatus::Active) {
            return Response::deny('Hosting panel is only available for active services.');
        }

        if (! $service->node || $service->node->type !== 'directadmin') {
            return Response::deny('This hosting service is not linked to a DirectAdmin server.');
        }

        return $user->is_admin || $user->id === $service->user_id
            ? Response::allow()
            : Response::deny('You can only manage hosting for your own services.');
    }

    /**
     * Customer can manage Mailcow email hosting for their own active email services.
     */
    public function manageEmailHosting(User $user, Service $service): Response
    {
        if (! $service->isEmailHosting()) {
            return Response::deny('Email console is only available for email hosting services.');
        }

        if ($service->status !== ServiceStatus::Active) {
            return Response::deny('Email console is only available for active services.');
        }

        return $user->is_admin || $user->id === $service->user_id
            ? Response::allow()
            : Response::deny('You can only manage email for your own services.');
    }
}
