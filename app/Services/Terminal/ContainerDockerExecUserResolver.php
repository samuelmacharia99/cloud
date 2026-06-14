<?php

namespace App\Services\Terminal;

class ContainerDockerExecUserResolver
{
    /**
     * Templates whose images define a www-data user and expect app files owned by it.
     *
     * @var list<string>
     */
    private const WWW_DATA_TEMPLATES = ['laravel', 'php', 'wordpress'];

    /**
     * Docker exec user for the given stack, or null to use the container's default USER.
     */
    public static function execUser(?string $templateSlug): ?string
    {
        if ($templateSlug !== null && in_array($templateSlug, self::WWW_DATA_TEMPLATES, true)) {
            return 'www-data';
        }

        return null;
    }

    /**
     * "-u user " flag for docker exec, or empty when the container default user should be used.
     */
    public static function execUserFlag(?string $templateSlug): string
    {
        $user = self::execUser($templateSlug);

        return $user !== null ? '-u '.escapeshellarg($user).' ' : '';
    }
}
