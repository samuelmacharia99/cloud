<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Service;
use Closure;

/**
 * Every hostname a deployed application may be addressed by: its bound
 * domains, its platform hostname, and the loopback names the node-side
 * readiness and Doctor probes use with a Host header.
 *
 * Applications that validate the Host header (Open Source POS refuses to
 * start in production without an allow-list) are configured from this list,
 * and re-configured through ContainerAllowedHostnamesSync whenever it changes.
 */
class ContainerAllowedHostnamesResolver
{
    /** Names the in-node probes send; always allowed. */
    public const LOCAL_HOSTNAMES = ['localhost', '127.0.0.1'];

    /**
     * @param  (Closure(ContainerDeployment): ?string)|null  $platformHostname
     */
    public function __construct(
        private ?Closure $platformHostname = null,
    ) {}

    /**
     * @return list<string> lowercase, unique, sorted, loopback names last
     */
    public function hostnamesFor(Service $service): array
    {
        $service->loadMissing('containerDeployment.domains');
        $deployment = $service->containerDeployment;

        $hostnames = [];
        foreach ($deployment?->domains ?? [] as $domain) {
            $name = $this->normalize((string) $domain->domain);
            if ($name !== '') {
                $hostnames[$name] = true;
            }
        }

        if ($deployment) {
            $platform = $this->normalize((string) ($this->platformHostnameFor($deployment) ?? ''));
            if ($platform !== '') {
                $hostnames[$platform] = true;
            }
        }

        $names = array_keys($hostnames);
        sort($names);

        return array_values(array_unique([...$names, ...self::LOCAL_HOSTNAMES]));
    }

    public function commaList(Service $service): string
    {
        return implode(',', $this->hostnamesFor($service));
    }

    private function platformHostnameFor(ContainerDeployment $deployment): ?string
    {
        if ($this->platformHostname !== null) {
            return ($this->platformHostname)($deployment);
        }

        // Unit tests render environments under a bare container with no
        // settings store; there is no platform zone to consult then.
        if (! app()->bound('config')) {
            return null;
        }

        return app(PlatformAppsDomainService::class)->hostnameFor($deployment);
    }

    private function normalize(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));
        $hostname = preg_replace('#^https?://#', '', $hostname) ?? $hostname;

        return rtrim($hostname, '/.');
    }
}
