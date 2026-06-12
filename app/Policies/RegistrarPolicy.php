<?php

namespace App\Policies;

use App\Models\Registrar;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class RegistrarPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can manage registrars.');
    }

    public function create(User $user): Response
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Registrar $registrar): Response
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Registrar $registrar): Response
    {
        return $this->viewAny($user);
    }
}
