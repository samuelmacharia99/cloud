<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeploymentEvent;
use App\Models\ContainerDomain;
use App\Models\Domain;
use App\Models\Service;
use App\Services\Dns\DomainCloudflareDnsService;
use Illuminate\Support\Facades\DB;
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

    public function bindHostname(
        Service $service,
        string $hostname,
        string $purpose = ContainerDomain::PURPOSE_WEB,
    ): ?ContainerDomain {
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
            'purpose' => $purpose,
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

    public function bindApiHostname(Service $service, string $hostname): ContainerDomain
    {
        $hostname = $this->normalizeHostname($hostname);
        $service->loadMissing(['user', 'containerDeployment.node']);
        $deployment = $service->containerDeployment;
        if (($service->effectiveContainerTemplate()?->slug ?? '') !== 'nodejs'
            || ! ContainerNodeWorkloadTopologyService::isApiOnly($service)) {
            throw new \DomainException(
                'Dedicated API domains require an API-only Node.js service without a browser frontend.'
            );
        }
        if (! $deployment?->node || $hostname === '' || ! str_contains($hostname, '.')) {
            throw new \DomainException('A running deployment and valid API hostname are required.');
        }

        $domain = DB::transaction(function () use ($deployment, $hostname): ContainerDomain {
            $deployment->newQuery()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $current = ContainerDomain::query()
                ->where('container_deployment_id', $deployment->id)
                ->where('purpose', ContainerDomain::PURPOSE_API)
                ->first();
            if ($current && $current->domain !== $hostname) {
                throw new \DomainException(
                    "This service already has API endpoint {$current->domain}. Edit or remove it before adding another."
                );
            }
            $claimed = ContainerDomain::query()->where('domain', $hostname)->first();
            if ($claimed && (int) $claimed->container_deployment_id !== (int) $deployment->id) {
                throw new \DomainException('That hostname is already attached to another application.');
            }
            if ($claimed) {
                $claimed->update(['purpose' => ContainerDomain::PURPOSE_API]);

                return $claimed;
            }

            return ContainerDomain::query()->create([
                'container_deployment_id' => $deployment->id,
                'domain' => $hostname,
                'purpose' => ContainerDomain::PURPOSE_API,
                'status' => 'pending',
            ]);
        });

        $managedDns = false;
        $nginxBound = false;
        try {
            $managedDns = $this->syncManagedARecordStrict(
                $service,
                $hostname,
                (string) $deployment->node->ip_address,
            );
            $this->nginx->bind($domain->fresh());
            $nginxBound = true;
            $domain = $domain->fresh();
            $this->attemptAutoSsl($service, $domain, (string) $deployment->node->ip_address);
            $service->refresh();
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['api_hostname'] = $hostname;
            $meta['api_url'] = 'https://'.$hostname;
            $service->update(['service_meta' => $meta]);
            $this->recordApiDomainEvent($service, 'api_domain_bound', [
                'hostname' => $hostname,
                'managed_dns' => $managedDns,
                'ssl_enabled' => (bool) $domain->fresh()->ssl_enabled,
            ]);

            return $domain->fresh();
        } catch (\Throwable $e) {
            if ($nginxBound) {
                try {
                    $this->nginx->removeProxyConfig($domain);
                    $this->nginx->cleanupSslCertificate($domain);
                } catch (\Throwable $cleanupError) {
                    Log::critical('Failed to compensate API hostname nginx binding', [
                        'service_id' => $service->id,
                        'hostname' => $hostname,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }
            if ($managedDns) {
                try {
                    $platformDomain = $this->dns->resolvePlatformDomainForHostname(
                        (int) $service->user_id,
                        $hostname,
                    );
                    if ($platformDomain) {
                        $this->dns->deleteARecordForHostname($platformDomain, $hostname);
                    }
                } catch (\Throwable $cleanupError) {
                    Log::critical('Failed to compensate API hostname managed DNS', [
                        'service_id' => $service->id,
                        'hostname' => $hostname,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }
            $service->refresh();
            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            unset($meta['api_hostname'], $meta['api_url']);
            $service->update(['service_meta' => $meta]);
            $domain->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            try {
                $this->recordApiDomainEvent($service, 'api_domain_bind_failed', [
                    'hostname' => $hostname,
                    'error' => $e->getMessage(),
                ]);
            } catch (\Throwable $auditError) {
                Log::critical('API hostname failure could not be audited', [
                    'service_id' => $service->id,
                    'hostname' => $hostname,
                    'error' => $auditError->getMessage(),
                ]);
            }
            throw new \RuntimeException('API hostname provisioning failed: '.$e->getMessage(), 0, $e);
        }
    }

    public function clearApiHostnameMetadata(Service $service, string $hostname): ?string
    {
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        unset($meta['api_hostname'], $meta['api_url']);
        $service->update(['service_meta' => $meta]);
        $dnsWarning = null;
        $platformDomain = $this->dns->resolvePlatformDomainForHostname((int) $service->user_id, $hostname);
        if ($platformDomain) {
            $result = $this->dns->deleteARecordForHostname($platformDomain, $hostname);
            if (! ($result['success'] ?? false)) {
                $dnsWarning = 'The API route was removed, but its managed DNS A record could not be deleted: '
                    .($result['message'] ?? 'unknown error');
                Log::warning($dnsWarning, [
                    'service_id' => $service->id,
                    'hostname' => $hostname,
                ]);
            }
        }
        $this->recordApiDomainEvent($service, 'api_domain_unbound', [
            'hostname' => $this->normalizeHostname($hostname),
            'dns_cleanup_warning' => $dnsWarning,
        ]);

        return $dnsWarning;
    }

    /**
     * Point platform-managed DNS A records at the current container host.
     * Used after bind, redeploy, and live migration so traffic follows the node IP.
     */
    public function syncManagedARecords(Service $service): void
    {
        $service->loadMissing(['containerDeployment.node', 'containerDeployment.domains']);
        $deployment = $service->containerDeployment;
        $nodeIp = (string) ($deployment?->node?->ip_address ?? '');
        if ($nodeIp === '' || ! $deployment) {
            return;
        }

        $domains = $deployment->domains ?? collect();
        foreach ($domains as $domain) {
            if (! in_array($domain->status, ['active', 'pending'], true)) {
                continue;
            }

            $this->syncManagedARecord($service, (string) $domain->domain, $nodeIp);
        }
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

    private function syncManagedARecordStrict(Service $service, string $hostname, string $nodeIp): bool
    {
        if ($nodeIp === '') {
            throw new \DomainException('The container host has no public IP address.');
        }
        $platformDomain = $this->dns->resolvePlatformDomainForHostname((int) $service->user_id, $hostname);
        if (! $platformDomain) {
            return false;
        }
        $result = $this->dns->upsertARecord($platformDomain, $hostname, $nodeIp);
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException(
                'Managed DNS could not create the API A record: '.($result['message'] ?? 'unknown error')
            );
        }

        return true;
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordApiDomainEvent(Service $service, string $event, array $payload): void
    {
        ContainerDeploymentEvent::query()->create([
            'service_id' => $service->id,
            'container_deployment_id' => $service->containerDeployment?->id,
            'event' => $event,
            'payload' => $payload,
            'recorded_at' => now(),
        ]);
    }
}
