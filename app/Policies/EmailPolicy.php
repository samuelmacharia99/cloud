<?php

namespace App\Policies;

use App\Models\Email;
use App\Models\User;

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

    public function resend(User $user, Email $email): bool
    {
        return $user->is_admin && in_array($email->status, ['failed', 'bounced'], true);
    }
}
