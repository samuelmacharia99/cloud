<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Setting;
use Illuminate\Auth\Access\Response;

class SettingPolicy
{
    /**
     * Only admin can view settings.
     */
    public function viewAny(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can view settings.');
    }

    /**
     * Only admin can view individual setting.
     */
    public function view(User $user, Setting $setting): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can view settings.');
    }

    /**
     * Only admin can update settings.
     */
    public function update(User $user, Setting $setting): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can update settings.');
    }

    /**
     * Settings cannot be deleted.
     */
    public function delete(User $user, Setting $setting): Response
    {
        return Response::deny('Settings cannot be deleted.');
    }

    /**
     * Only admin can perform batch updates of multiple settings.
     */
    public function batchUpdate(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can update settings.');
    }

    /**
     * Only admin can reset settings to defaults.
     */
    public function reset(User $user): Response
    {
        return $user->is_admin
            ? Response::allow()
            : Response::deny('Only administrators can reset settings.');
    }
}
