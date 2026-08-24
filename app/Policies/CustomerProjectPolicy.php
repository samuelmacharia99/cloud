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
}
