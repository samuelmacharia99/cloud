<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDomain;
use App\Models\Domain;
use App\Models\Service;
use App\Services\Dns\DomainCloudflareDnsService;
use Illuminate\Support\Facades\Log;

class ContainerDomainBindingService
{
    public function __construct(
        private NginxProxyService $nginx,
        private DomainCloudflareDnsService $dns,
    ) {}

    /**
     * Apex + www hostnames that should both serve this site.
     *
     * @return list<string>
     */
    public function hostnamesFor(string $hostname): array
    {
        $host = $this->normalizeHostname($hostname);
        if ($host === '' || ! str_contains($host, '.')) {
            return $host === '' ? [] : [$host];
        }

        $apex = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if ($apex === '' || ! str_contains($apex, '.')) {
            return [$host];
        }

        return array_values(array_unique([$apex, 'www.'.$apex]));
    }

    /**
     * Bind the service's primary domain and its www twin after deploy.
     *
     * @return list<ContainerDomain>
     */
    public function attachPrimaryHosts(Service $service): array
    {
        $hostname = $this->resolvePrimaryHostname($service);
        if ($hostname === null) {
            return [];
        }

        return $this->bindHostnamePair($service, $hostname);
    }

    /**
     * Bind a requested hostname plus www/apex so both open the app.
     *
     * @return list<ContainerDomain>
     */
    public function bindHostnamePair(Service $service, string $hostname): array
    {
        $bound = [];

        foreach ($this->hostnamesFor($hostname) as $host) {
            $domain = $this->bindHostname($service, $host);
            if ($domain) {
                $bound[] = $domain;
            }
        }

        if ($bound !== []) {
            $this->syncViteHosts($service);
        }

        return $bound;
    }

    public function bindHostname(Service $service, string $hostname): ?ContainerDomain
    {
        $hostname = $this->normalizeHostname($hostname);
        if ($hostname === '' || ! str_contains($hostname, '.')) {
            return null;
        }

        $service->loadMissing(['user', 'containerDeployment.node']);
        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return null;
        }

        $existing = ContainerDomain::query()->where('domain', $hostname)->first();
        if ($existing && (int) $existing->container_deployment_id !== (int) $deployment->id) {
            Log::warning('Skipped binding hostname already attached to another application', [
                'service_id' => $service->id,
                'domain' => $hostname,
                'other_deployment_id' => $existing->container_deployment_id,
            ]);

            return null;
        }

        $domain = $existing ?? ContainerDomain::query()->create([
            'container_deployment_id' => $deployment->id,
            'domain' => $hostname,
            'status' => 'pending',
        ]);

        $this->syncManagedARecord($service, $hostname, (string) ($deployment->node?->ip_address ?? ''));

        try {
            $this->nginx->bind($domain->fresh());
            $domain = $domain->fresh();
        } catch (\Throwable $e) {
            Log::warning('Hostname recorded but nginx bind failed', [
                'service_id' => $service->id,
                'domain' => $hostname,
                'error' => $e->getMessage(),
            ]);
        }

        $this->attemptAutoSsl($service, $domain, (string) ($deployment->node?->ip_address ?? ''));

        return $domain?->fresh();
    }

    public function resolvePrimaryHostname(Service $service): ?string
    {
        $service->loadMissing(['product', 'containerDeployment.domains']);
        $meta = is_array($service->service_meta) ? $service->service_meta : [];

        $candidates = [
            $meta['primary_domain'] ?? null,
            $meta['domain'] ?? null,
        ];

        if (! empty($meta['domain_id'])) {
            $record = Domain::query()->find($meta['domain_id']);
            $candidates[] = $record?->fqdn();
        }

        $candidates[] = $service->attachedDomainName();
        $candidates[] = $service->name;

        foreach ($candidates as $candidate) {
            $host = $this->normalizeHostname((string) $candidate);
            if ($host !== '' && str_contains($host, '.')) {
                return $host;
            }
        }

        return null;
    }

    private function syncManagedARecord(Service $service, string $hostname, string $nodeIp): void
    {
        if ($nodeIp === '') {
            return;
        }

        $platformDomain = $this->dns->resolvePlatformDomainForHostname((int) $service->user_id, $hostname);
        if (! $platformDomain) {
            return;
        }

        $result = $this->dns->upsertARecord($platformDomain, $hostname, $nodeIp);
        if (! ($result['success'] ?? false)) {
            Log::warning('Managed DNS A record was not updated', [
                'service_id' => $service->id,
                'domain' => $hostname,
                'message' => $result['message'] ?? 'unknown',
            ]);
        }
    }

    private function attemptAutoSsl(Service $service, ?ContainerDomain $domain, string $nodeIp): void
    {
        if (! $domain || $nodeIp === '' || $domain->ssl_enabled) {
            return;
        }

        if (! $this->nginx->checkDns($domain->domain, $nodeIp)) {
            return;
        }

        try {
            $this->nginx->enableSsl($domain);
        } catch (\Throwable $e) {
            Log::info('Auto SSL skipped after domain bind', [
                'service_id' => $service->id,
                'domain' => $domain->domain,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncViteHosts(Service $service): void
    {
        $deployment = $service->containerDeployment;
        if (! $deployment) {
            return;
        }

        try {
            app(ContainerDeploymentService::class)->syncViteAllowedHosts($service, $deployment);
        } catch (\Throwable $e) {
            Log::warning('Failed to allow bound domains on the Vite preview server', [
                'service_id' => $service->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function normalizeHostname(string $hostname): string
    {
        $host = strtolower(trim($hostname));
        $host = preg_replace('#^https?://#', '', $host) ?? $host;
        $host = explode('/', $host)[0];
        $host = explode(':', $host)[0];

        return rtrim($host, '.');
    }
}
