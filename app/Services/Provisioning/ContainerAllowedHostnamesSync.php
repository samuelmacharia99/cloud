<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a running application's Host allow-list in step with the domains
 * bound to it. Called after a domain is bound or removed and after the
 * platform hostname is attached. Only stacks that declare a hostname
 * environment key are touched, and only when the value actually changed,
 * because applying environment recreates the container.
 */
class ContainerAllowedHostnamesSync
{
    /** Template slug => environment key holding the comma-separated allow-list. */
    public const HOSTNAME_ENV_KEYS = [
        'ospos' => 'ALLOWED_HOSTNAMES',
    ];

    public function __construct(
        private ContainerAllowedHostnamesResolver $resolver,
        private ContainerEnvironmentService $environment,
    ) {}

    public static function hostnameEnvKey(?string $slug): ?string
    {
        return self::HOSTNAME_ENV_KEYS[strtolower((string) $slug)] ?? null;
    }

    /**
     * @return array{changed: bool, key: ?string, value: ?string}
     */
    public function sync(Service $service): array
    {
        $slug = $service->effectiveContainerTemplate()?->slug;
        $key = self::hostnameEnvKey($slug);
        if ($key === null) {
            return ['changed' => false, 'key' => null, 'value' => null];
        }

        $service->loadMissing('containerDeployment');
        $deployment = $service->containerDeployment;
        if (! $deployment || in_array((string) $deployment->status, ['terminated'], true)) {
            return ['changed' => false, 'key' => $key, 'value' => null];
        }

        $value = $this->resolver->commaList($service);
        $current = (string) (($deployment->env_values ?? [])[$key] ?? '');
        if ($current === $value) {
            return ['changed' => false, 'key' => $key, 'value' => $value];
        }

        $this->environment->updateVariables($service, [$key => $value], restart: true);

        Log::info('Allowed hostnames synced to the running application', [
            'service_id' => $service->id,
            'key' => $key,
            'hostnames' => $value,
        ]);

        return ['changed' => true, 'key' => $key, 'value' => $value];
    }

    /**
     * Fail-soft variant for callers that have already done the real work
     * (a domain is bound; the allow-list refresh must not undo that).
     */
    public function syncQuietly(Service $service, string $context): void
    {
        try {
            $this->sync($service);
        } catch (\Throwable $e) {
            Log::warning('Allowed hostnames could not be applied to the running application', [
                'service_id' => $service->id,
                'context' => $context,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
