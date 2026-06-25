<?php

namespace App\Services\Dns;

use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\Service;
use App\Models\Setting;
use App\Services\NodeNameserverService;
use Illuminate\Support\Facades\Log;

class DomainCloudflareDnsService
{
    public function __construct(
        private CloudflareDnsService $cloudflare,
        private NodeNameserverService $nameservers,
    ) {}

    public function isAvailable(): bool
    {
        return $this->cloudflare->isConfigured();
    }

    public function usesCloudflareDns(Domain $domain): bool
    {
        return (bool) $domain->cloudflare_dns_enabled && filled($domain->cloudflare_zone_id);
    }

    public function shouldOfferCloudflareDns(?Domain $domain = null): bool
    {
        if (! $this->isAvailable()) {
            return false;
        }

        if ($domain && $this->hasDirectAdminDns($domain)) {
            return false;
        }

        return true;
    }

    public function hasDirectAdminDns(Domain $domain): bool
    {
        $fqdn = strtolower($domain->fqdn());

        return Service::query()
            ->where('user_id', $domain->user_id)
            ->where('status', 'active')
            ->where(function ($query) use ($domain, $fqdn) {
                $query->whereJsonContains('service_meta->domain_id', $domain->id)
                    ->orWhere('name', $fqdn)
                    ->orWhere('service_meta->primary_domain', $fqdn);
            })
            ->where(function ($query) {
                $query->where('provisioning_driver_key', 'directadmin')
                    ->orWhereHas('product', fn ($q) => $q->where('provisioning_driver_key', 'directadmin'));
            })
            ->exists();
    }

    /**
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function nameserversForRegistration(): array
    {
        $branded = $this->cloudflare->brandedNameservers();

        if (filled($branded['ns1'])) {
            return $this->packNameservers([
                $branded['ns1'],
                $branded['ns2'] ?? '',
                $branded['ns3'] ?? '',
                $branded['ns4'] ?? '',
            ]);
        }

        return $this->nameservers->platformDefaults();
    }

    /**
     * @return array{success: bool, message: string, zone?: DnsZone}
     */
    public function provisionZone(Domain $domain): array
    {
        if (! $this->isAvailable()) {
            return ['success' => false, 'message' => 'Cloudflare DNS is not configured. Contact support.'];
        }

        if ($this->hasDirectAdminDns($domain)) {
            return ['success' => false, 'message' => 'This domain is managed through your shared hosting control panel.'];
        }

        $fqdn = strtolower($domain->fqdn());

        if ($domain->cloudflare_zone_id) {
            $zone = $this->ensureLocalZone($domain, $domain->cloudflare_zone_id);

            return ['success' => true, 'message' => 'DNS zone already provisioned.', 'zone' => $zone];
        }

        $created = $this->cloudflare->createZone($fqdn);

        if (! $created['success']) {
            $existing = $this->cloudflare->findZoneByName($fqdn);
            if (! $existing['success']) {
                return ['success' => false, 'message' => $created['message']];
            }
            $created = $existing;
        }

        $zoneId = (string) ($created['zone_id'] ?? '');
        if ($zoneId === '') {
            return ['success' => false, 'message' => 'Cloudflare did not return a zone ID.'];
        }

        $this->applyNameserversToDomain($domain, $created['nameservers'] ?? []);

        $domain->update([
            'cloudflare_dns_enabled' => true,
            'cloudflare_zone_id' => $zoneId,
        ]);

        $zone = $this->ensureLocalZone($domain->fresh(), $zoneId);

        Log::info('Cloudflare DNS zone provisioned', [
            'domain_id' => $domain->id,
            'fqdn' => $fqdn,
            'zone_id' => $zoneId,
        ]);

        return ['success' => true, 'message' => 'DNS zone provisioned successfully.', 'zone' => $zone];
    }

    public function provisionFromServiceMeta(Domain $domain, array $serviceMeta): void
    {
        if (empty($serviceMeta['cloudflare_dns'])) {
            return;
        }

        $domain->update(['cloudflare_dns_enabled' => true]);
        $this->provisionZone($domain->fresh());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecords(Domain $domain): array
    {
        if (! $this->usesCloudflareDns($domain)) {
            return [];
        }

        $result = $this->cloudflare->listRecords((string) $domain->cloudflare_zone_id);

        return $result['success'] ? ($result['records'] ?? []) : [];
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function addRecord(Domain $domain, string $name, string $type, string $content, int $ttl = 3600, ?int $priority = null): array
    {
        $zoneId = $this->requireZoneId($domain);

        return $this->cloudflare->createRecord(
            $zoneId,
            $type,
            $this->qualifyRecordName($domain, $name),
            $content,
            $ttl,
            $priority,
        );
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateRecord(Domain $domain, string $recordId, string $name, string $type, string $content, int $ttl = 3600, ?int $priority = null): array
    {
        $zoneId = $this->requireZoneId($domain);

        return $this->cloudflare->updateRecord(
            $zoneId,
            $recordId,
            $type,
            $this->qualifyRecordName($domain, $name),
            $content,
            $ttl,
            $priority,
        );
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function deleteRecord(Domain $domain, string $recordId): array
    {
        return $this->cloudflare->deleteRecord($this->requireZoneId($domain), $recordId);
    }

    /**
     * Create or update an A record for container routing.
     *
     * @return array{success: bool, message: string}
     */
    public function upsertARecord(Domain $domain, string $host, string $ip): array
    {
        $provisioned = $this->provisionZone($domain);
        if (! $provisioned['success']) {
            return $provisioned;
        }

        $domain->refresh();
        $qualifiedHost = $this->qualifyRecordName($domain, $host);
        $records = $this->listRecords($domain);

        foreach ($records as $record) {
            if (strtoupper($record['type']) === 'A' && strtolower($record['name']) === strtolower($qualifiedHost)) {
                return $this->cloudflare->updateRecord(
                    (string) $domain->cloudflare_zone_id,
                    (string) $record['id'],
                    'A',
                    $qualifiedHost,
                    $ip,
                    (int) ($record['ttl'] ?? 3600),
                );
            }
        }

        return $this->addRecord($domain, $host, 'A', $ip);
    }

    public function resolvePlatformDomainForHostname(int $userId, string $hostname): ?Domain
    {
        $hostname = strtolower(trim($hostname));

        return Domain::query()
            ->where('user_id', $userId)
            ->where('cloudflare_dns_enabled', true)
            ->get()
            ->first(function (Domain $domain) use ($hostname) {
                $fqdn = strtolower($domain->fqdn());

                return $hostname === $fqdn || str_ends_with($hostname, '.'.$fqdn);
            });
    }

    private function requireZoneId(Domain $domain): string
    {
        if (! $this->usesCloudflareDns($domain)) {
            $provisioned = $this->provisionZone($domain);
            if (! $provisioned['success']) {
                throw new \RuntimeException($provisioned['message']);
            }
            $domain->refresh();
        }

        $zoneId = (string) $domain->cloudflare_zone_id;
        if ($zoneId === '') {
            throw new \RuntimeException('Cloudflare zone is not provisioned for this domain.');
        }

        return $zoneId;
    }

    private function ensureLocalZone(Domain $domain, string $zoneId): DnsZone
    {
        return DnsZone::query()->updateOrCreate(
            ['domain_id' => $domain->id, 'provider' => 'cloudflare'],
            [
                'name' => $domain->fqdn(),
                'status' => 'active',
                'external_zone_id' => $zoneId,
            ],
        );
    }

    /**
     * @param  list<string>  $cloudflareNameservers
     */
    private function applyNameserversToDomain(Domain $domain, array $cloudflareNameservers): void
    {
        $branded = $this->cloudflare->brandedNameservers();
        $source = filled($branded['ns1'])
            ? $branded
            : $this->packNameservers($cloudflareNameservers);

        $domain->update([
            'nameserver_1' => $source['ns1'] ?? null,
            'nameserver_2' => $source['ns2'] ?? null,
            'nameserver_3' => $source['ns3'] ?? null,
            'nameserver_4' => $source['ns4'] ?? null,
        ]);
    }

    /**
     * @param  list<string>  $nameservers
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    private function packNameservers(array $nameservers): array
    {
        $nameservers = array_values(array_filter($nameservers));

        return [
            'ns1' => $nameservers[0] ?? '',
            'ns2' => $nameservers[1] ?? null,
            'ns3' => $nameservers[2] ?? null,
            'ns4' => $nameservers[3] ?? null,
        ];
    }

    private function qualifyRecordName(Domain $domain, string $name): string
    {
        $name = trim($name);
        $fqdn = strtolower($domain->fqdn());

        if ($name === '' || $name === '@') {
            return $fqdn;
        }

        if (str_contains($name, '.')) {
            return strtolower($name);
        }

        return strtolower($name.'.'.$fqdn);
    }
}
