<?php

namespace App\Policies;

use App\Models\User;
use App\Models\SmsLog;

class SmsLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_admin;
    }

    public function view(User $user, SmsLog $smsLog): bool
    {
        return $user->is_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_admin;
    }

    public function update(User $user, SmsLog $smsLog): bool
    {
        return $user->is_admin;
    }

    public function delete(User $user, SmsLog $smsLog): bool
    {
        return $user->is_admin;
    }
}
