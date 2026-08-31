<?php

namespace App\Policies;

use App\Models\CustomerProject;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CustomerProjectPolicy
{
    public function create(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, CustomerProject $customerProject): Response
    {
        return $user->is_admin || $user->id === $customerProject->user_id
            ? Response::allow()
            : Response::deny('You can only view your own projects.');
    }

    public function rename(User $user, CustomerProject $customerProject): Response
    {
        return $user->is_admin || $user->id === $customerProject->user_id
            ? Response::allow()
            : Response::deny('You can only rename your own projects.');
    }

    public function update(User $user, CustomerProject $customerProject): Response
    {
        return $this->rename($user, $customerProject);
    }

    public function delete(User $user, CustomerProject $customerProject): Response
    {
        return $user->is_admin || $user->id === $customerProject->user_id
            ? Response::allow()
            : Response::deny('You can only remove your own projects.');
    }

    public function deployWorkload(User $user, CustomerProject $customerProject): Response
    {
        if (! $user->is_admin && $user->id !== $customerProject->user_id) {
            return Response::deny('You can only deploy into your own projects.');
        }

        if (! $customerProject->canDeployIncludedWorkload()) {
            return Response::deny('This project needs an active Application Hosting plan before you can deploy another service.');
        }

        return Response::allow();
    }
}
