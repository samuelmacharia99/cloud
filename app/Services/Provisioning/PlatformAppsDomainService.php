<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Models\Node;
use App\Models\Service;
use App\Models\Setting;
use App\Services\Dns\CloudflareDnsService;
use App\Services\SSH\SSHService;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * A hostname every stack has from the moment it deploys.
 *
 * Published ports are bound to loopback now, so http://node:port, which used
 * to be the only address a stack had before a customer bound a domain, no
 * longer answers. Each stack gets {name}.{apps zone} instead: an A record on
 * the platform's own Cloudflare zone, an nginx vhost like any other domain,
 * and TLS from the node's wildcard certificate. Customers see it in the
 * Domains tab as read-only; their own domains take precedence in every URL
 * the platform hands out.
 *
 * Everything here is fail-soft on the deploy path. A deploy that cannot get
 * its platform hostname still deploys; the failure is recorded on the row and
 * in the deployment's event timeline, and the next deploy or rebind retries.
 */
class PlatformAppsDomainService
{
    public const SETTING_ZONE = 'platform_apps_zone';

    public const SETTING_ZONE_ID = 'platform_apps_cloudflare_zone_id';

    public const SETTING_DNS_TOKEN = 'platform_apps_dns_api_token';

    public const EVENT_ATTACHED = 'platform_hostname_attached';

    public const EVENT_FAILED = 'platform_hostname_failed';

    public const EVENT_RELEASED = 'platform_hostname_released';

    private const RECORD_TTL = 300;

    public function __construct(
        private CloudflareDnsService $cloudflare,
        private NginxProxyService $nginx,
        private PlatformWildcardCertificateService $certificates,
        private ContainerDeploymentEventRecorder $events,
        private ?Closure $sshFactory = null,
    ) {}

    public function zone(): ?string
    {
        $zone = strtolower(trim((string) Setting::getValue(self::SETTING_ZONE, ''), " \t\n\r."));

        return $zone !== '' && preg_match('/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $zone) === 1
            ? $zone
            : null;
    }

    public function zoneId(): ?string
    {
        $id = trim((string) Setting::getValue(self::SETTING_ZONE_ID, ''));

        return $id !== '' ? $id : null;
    }

    public function dnsToken(): ?string
    {
        $token = trim((string) Setting::getValue(self::SETTING_DNS_TOKEN, ''));

        return $token !== '' ? $token : null;
    }

    /**
     * Off until an operator names the zone. The panel's Cloudflare token
     * manages the records; the narrower DNS token only travels to nodes for
     * certificate issuance.
     */
    public function isEnabled(): bool
    {
        return $this->zone() !== null
            && $this->zoneId() !== null
            && $this->dnsToken() !== null
            && $this->cloudflare->apiToken() !== null;
    }

    public function hostnameFor(ContainerDeployment $deployment): ?string
    {
        $zone = $this->zone();
        if ($zone === null) {
            return null;
        }

        $label = strtolower(trim((string) $deployment->container_name));
        $label = preg_replace('/[^a-z0-9-]+/', '-', $label) ?? '';
        $label = trim(preg_replace('/-{2,}/', '-', $label) ?? '', '-');
        if ($label === '') {
            $label = 'app-'.$deployment->service_id;
        }

        return substr($label, 0, 63).'.'.$zone;
    }

    public function platformDomainFor(ContainerDeployment $deployment): ?ContainerDomain
    {
        return ContainerDomain::query()
            ->where('container_deployment_id', $deployment->id)
            ->where('purpose', ContainerDomain::PURPOSE_PLATFORM)
            ->first();
    }

    /**
     * Give the service its platform hostname, or bring an existing one back
     * in line with where the stack now runs. Never throws.
     */
    public function attach(Service $service): ?ContainerDomain
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $service->loadMissing(['containerDeployment.node', 'containerDeployment.domains']);
        $deployment = $service->containerDeployment;
        $node = $deployment?->node;
        if (! $deployment || ! $node || trim((string) $node->ip_address) === '') {
            return null;
        }

        $hostname = $this->hostnameFor($deployment);
        if ($hostname === null) {
            return null;
        }

        $claimed = ContainerDomain::query()->where('domain', $hostname)->first();
        if ($claimed && (int) $claimed->container_deployment_id !== (int) $deployment->id) {
            $this->recordFailure($service, $deployment, $hostname, "Hostname is attached to deployment {$claimed->container_deployment_id}.");

            return null;
        }

        $domain = $this->platformDomainFor($deployment);
        if ($domain && $domain->domain !== $hostname) {
            // The container was renamed. The old record and vhost are cleaned
            // up before the new name is claimed, so nothing dangles.
            $this->release($domain);
            $domain = null;
        }

        $domain ??= $claimed ?? ContainerDomain::query()->create([
            'container_deployment_id' => $deployment->id,
            'domain' => $hostname,
            'purpose' => ContainerDomain::PURPOSE_PLATFORM,
            'status' => 'pending',
        ]);
        if ($domain->purpose !== ContainerDomain::PURPOSE_PLATFORM) {
            $domain->update(['purpose' => ContainerDomain::PURPOSE_PLATFORM]);
        }

        try {
            $this->upsertRecord($hostname, (string) $node->ip_address);

            $ssh = $this->ssh($node);
            try {
                $paths = $this->certificates->ensureOnNode($ssh, $node, (string) $this->zone(), (string) $this->dnsToken(), $this->adminEmail());
            } finally {
                $ssh->disconnect();
            }

            $domain->update([
                'ssl_enabled' => true,
                'ssl_certificate_path' => $paths['cert'],
                'ssl_key_path' => $paths['key'],
                'error_message' => null,
            ]);
            $this->nginx->bind($domain->fresh());

            $this->events->record($service, $deployment, self::EVENT_ATTACHED, [
                'hostname' => $hostname,
                'node_id' => $node->id,
            ]);

            return $domain->fresh();
        } catch (\Throwable $e) {
            $domain->update([
                'status' => filled($domain->nginx_config_path) ? 'active' : 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $this->recordFailure($service, $deployment, $hostname, $e->getMessage());

            return $domain->fresh();
        }
    }

    /**
     * Before vhosts are rewritten on a node the stack just landed on: the
     * vhost names the wildcard's files, so they have to be there first or
     * nginx -t refuses the whole reload.
     */
    public function ensureCertificateOnNode(ContainerDeployment $deployment): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $domain = $this->platformDomainFor($deployment);
        $node = $deployment->node;
        if (! $domain || ! $node) {
            return;
        }

        $ssh = $this->ssh($node);
        try {
            $paths = $this->certificates->ensureOnNode($ssh, $node, (string) $this->zone(), (string) $this->dnsToken(), $this->adminEmail());
        } finally {
            $ssh->disconnect();
        }

        if ($domain->ssl_certificate_path !== $paths['cert'] || $domain->ssl_key_path !== $paths['key'] || ! $domain->ssl_enabled) {
            $domain->update([
                'ssl_enabled' => true,
                'ssl_certificate_path' => $paths['cert'],
                'ssl_key_path' => $paths['key'],
            ]);
        }
    }

    /**
     * The A record follows the node after a migration or relocation.
     */
    public function pointAt(ContainerDomain $domain, string $nodeIp): void
    {
        if (! $this->isEnabled() || trim($nodeIp) === '') {
            return;
        }

        $this->upsertRecord((string) $domain->domain, trim($nodeIp));
    }

    /**
     * Drop the A record when the stack is terminated or renamed. The vhost
     * and row are the caller's to remove, as for every other domain.
     */
    public function release(ContainerDomain $domain): void
    {
        $zoneId = $this->zoneId();
        if ($zoneId === null) {
            return;
        }

        $hostname = (string) $domain->domain;
        $recordId = $this->findRecordId($hostname);
        if ($recordId !== null) {
            $result = $this->cloudflare->deleteRecord($zoneId, $recordId);
            if (! ($result['success'] ?? false)) {
                Log::warning('Platform hostname A record could not be deleted', [
                    'hostname' => $hostname,
                    'message' => $result['message'] ?? 'unknown',
                ]);
            }
        }

        $deployment = $domain->deployment;
        if ($deployment?->service) {
            $this->events->record($deployment->service, $deployment, self::EVENT_RELEASED, ['hostname' => $hostname]);
        }
    }

    /**
     * Renew the wildcard on every node that serves a platform hostname.
     *
     * @return array{renewed: int, failed: int, errors: list<string>}
     */
    public function renewCertificates(): array
    {
        $report = ['renewed' => 0, 'failed' => 0, 'errors' => []];
        $zone = $this->zone();
        if ($zone === null) {
            return $report;
        }

        $nodeIds = ContainerDomain::query()
            ->where('container_domains.purpose', ContainerDomain::PURPOSE_PLATFORM)
            ->where('container_domains.status', 'active')
            ->join('container_deployments', 'container_deployments.id', '=', 'container_domains.container_deployment_id')
            ->whereNotNull('container_deployments.node_id')
            ->distinct()
            ->pluck('container_deployments.node_id');

        foreach (Node::query()->whereIn('id', $nodeIds)->where('is_active', true)->get() as $node) {
            try {
                $ssh = $this->ssh($node);
                try {
                    $this->certificates->renewOnNode($ssh, $node, $zone, $this->nginx);
                } finally {
                    $ssh->disconnect();
                }
                $report['renewed']++;
            } catch (\Throwable $e) {
                $report['failed']++;
                $report['errors'][] = "{$node->hostname}: {$e->getMessage()}";
                Log::error('Platform wildcard renewal failed', ['node_id' => $node->id, 'error' => $e->getMessage()]);
            }
        }

        return $report;
    }

    private function upsertRecord(string $hostname, string $ip): void
    {
        $zoneId = (string) $this->zoneId();
        $recordId = $this->findRecordId($hostname);

        $result = $recordId === null
            ? $this->cloudflare->createRecord($zoneId, 'A', $hostname, $ip, self::RECORD_TTL, null, false)
            : $this->cloudflare->updateRecord($zoneId, $recordId, 'A', $hostname, $ip, self::RECORD_TTL, null, false);

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException('Platform DNS record could not be written: '.($result['message'] ?? 'unknown error'));
        }
    }

    private function findRecordId(string $hostname): ?string
    {
        $result = $this->cloudflare->listRecords((string) $this->zoneId(), ['type' => 'A', 'name' => $hostname]);
        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException('Platform DNS zone could not be read: '.($result['message'] ?? 'unknown error'));
        }

        foreach ($result['records'] ?? [] as $record) {
            if (strtoupper((string) ($record['type'] ?? '')) === 'A' && strtolower((string) ($record['name'] ?? '')) === strtolower($hostname)) {
                return (string) $record['id'];
            }
        }

        return null;
    }

    private function recordFailure(Service $service, ContainerDeployment $deployment, string $hostname, string $error): void
    {
        Log::warning('Platform hostname could not be attached', [
            'service_id' => $service->id,
            'hostname' => $hostname,
            'error' => $error,
        ]);
        $this->events->record($service, $deployment, self::EVENT_FAILED, [
            'hostname' => $hostname,
            'error' => mb_substr($error, 0, 500),
        ]);
    }

    private function adminEmail(): string
    {
        return (string) Setting::getValue('admin_email', 'admin@talksasa.cloud');
    }

    private function ssh(Node $node): SSHService
    {
        return $this->sshFactory ? ($this->sshFactory)($node) : SSHService::forNode($node);
    }
}
