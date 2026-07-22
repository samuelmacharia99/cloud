<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class DomainPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Domain $domain): Response
    {
        return $user->id === $domain->user_id
            ? Response::allow()
            : Response::deny('You can only manage your own domains.');
    }

    public function update(User $user, Domain $domain): Response
    {
        return $this->view($user, $domain);
    }

    public function manageDns(User $user, Domain $domain): Response
    {
        return $this->view($user, $domain);
    }

    public function create(User $user): bool
    {
        return true;
    }
}
