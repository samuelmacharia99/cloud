<?php

namespace App\Policies;

use App\Models\CustomerProject;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CustomerProjectPolicy
{
    /**
     * Customer can rename their own project folder for personal reference.
     */
    public function rename(User $user, CustomerProject $customerProject): Response
    {
        return $user->is_admin || $user->id === $customerProject->user_id
            ? Response::allow()
            : Response::deny('You can only rename your own projects.');
    }
}
