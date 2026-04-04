<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Email;

class EmailPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, Email $email): bool
    {
        return $user->is_admin;
    }
}
