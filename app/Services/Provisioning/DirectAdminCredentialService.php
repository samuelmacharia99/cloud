<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Str;

class DirectAdminCredentialService
{
    public function generateUsername(User $user): string
    {
        $base = explode('@', $user->email)[0] ?? Str::slug($user->name);
        $username = strtolower(Str::slug(substr($base, 0, 16), ''));

        if ($username === '' || ! ctype_alpha($username[0])) {
            $username = 'u'.ltrim($username, '-');
        }

        $username = substr($username, 0, 16);

        $count = Service::where('service_meta->username', $username)->count();
        if ($count > 0) {
            $username = substr($username, 0, 13).substr(uniqid(), -3);
        }

        return substr($username, 0, 16);
    }

    public function generateUsernameFromService(Service $service): string
    {
        $base = Str::of($service->name)->lower()->replaceMatches('/[^a-z0-9]+/', '')->__toString();

        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'u'.$base;
        }

        $suffix = (string) $service->id;
        $base = substr($base, 0, max(1, 16 - strlen($suffix)));

        return substr($base.$suffix, 0, 16);
    }

    public function generatePassword(int $length = 16): string
    {
        $length = max(12, min(32, $length));

        $sets = [
            'lower' => 'abcdefghjkmnpqrstuvwxyz',
            'upper' => 'ABCDEFGHJKMNPQRSTUVWXYZ',
            'digit' => '23456789',
            'symbol' => '!@#$%^&*-_=+',
        ];

        $password = '';
        foreach ($sets as $chars) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }

        $all = implode('', $sets);
        for ($i = strlen($password); $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }
}
