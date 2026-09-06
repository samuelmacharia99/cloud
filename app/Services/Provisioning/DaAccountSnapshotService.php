<?php

namespace App\Services\Provisioning;

use App\Models\DaAccountSnapshot;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\DomainExtension;
use App\Models\Service;
use App\Models\User;
use App\Services\DomainInputParser;
use App\Services\Hosting\DirectAdminCustomerPanelApi;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pull a DirectAdmin account onto Talksasa before convert: sites, DBs, mail,
 * FTP, SSL, and every DNS record. DNS is written to dns_zones/dns_records so
 * the reseller dashboard does not need the DA panel.
 */
class DaAccountSnapshotService
{
    public const ZONE_PROVIDER = 'directadmin_import';

    public function __construct(
        private DirectAdminToContainerMigrationService $migrator,
        private DomainInputParser $parser,
        private DirectAdminToMailcowMigrationService $mail,
    ) {}

    public function capture(Service $service, ?DirectAdminCustomerPanelApi $api = null, ?User $actor = null): DaAccountSnapshot
    {
        if (! $service->isSharedHosting()) {
            throw new InvalidArgumentException('Only DirectAdmin shared hosting can be snapshotted.');
        }

        $service->loadMissing(['node', 'user', 'product']);
        if (! $service->node) {
            throw new InvalidArgumentException('This service has no DirectAdmin node.');
        }

        $api ??= DirectAdminCustomerPanelApi::forServiceNode($service->node);
        $inventory = $this->migrator->inventory($service);
        $username = (string) ($inventory['username'] ?? '');
        if ($username === '') {
            throw new InvalidArgumentException('DirectAdmin username is missing.');
        }

        $domains = $this->hostnamesFromInventory($inventory);
        $zones = [];
        $dnsFailures = [];
        $ownedZones = 0;

        foreach ($domains as $hostname) {
            $zone = $this->collectZone($api, $username, $hostname);
            if ($zone['dns_ok'] ?? false) {
                $ownedZones++;
            } elseif ($this->mail->isUnownedDirectAdminDomain((string) ($zone['dns_error'] ?? ''))) {
                $zone['dns_unowned'] = true;
                $zone['unowned_reason'] = $zone['dns_error'];
                $zone['dns_error'] = null;
            } else {
                $dnsFailures[] = $hostname.': '.($zone['dns_error'] ?? 'DNS list failed.');
            }
            $zones[] = $zone;
        }

        if ($domains === []) {
            $dnsFailures[] = 'DirectAdmin returned no domains to snapshot.';
        } elseif ($ownedZones === 0 && $dnsFailures === []) {
            $dnsFailures[] = 'DirectAdmin listed site folders but none are DNS zones this user can read.';
        }

        $payload = [
            'inventory' => $inventory,
            'zones' => $zones,
            'nameservers' => $inventory['account']['nameservers'] ?? [],
            'captured_at' => now()->toIso8601String(),
        ];

        $counts = $this->counts($inventory, $zones);

        if ($dnsFailures !== []) {
            return DaAccountSnapshot::query()->create([
                'service_id' => $service->id,
                'captured_by_user_id' => $actor?->id ?? Auth::id(),
                'username' => $username,
                'primary_domain' => $inventory['domain'] ?? ($domains[0] ?? null),
                ...$counts,
                'dns_imported' => false,
                'status' => 'failed',
                'error' => 'Could not capture DNS for: '.implode(' ', $dnsFailures),
                'payload' => $payload,
            ]);
        }

        return DB::transaction(function () use ($service, $actor, $username, $inventory, $domains, $zones, $payload, $counts): DaAccountSnapshot {
            $imported = 0;
            foreach ($zones as $zone) {
                if (! ($zone['dns_ok'] ?? false) || ($zone['dns_unowned'] ?? false)) {
                    continue;
                }
                $imported += $this->importZone($service, $zone);
            }

            $snapshot = DaAccountSnapshot::query()->create([
                'service_id' => $service->id,
                'captured_by_user_id' => $actor?->id ?? Auth::id(),
                'username' => $username,
                'primary_domain' => $inventory['domain'] ?? ($domains[0] ?? null),
                ...$counts,
                'dns_record_count' => $imported,
                'dns_imported' => true,
                'status' => 'captured',
                'error' => null,
                'payload' => $payload,
            ]);

            $meta = is_array($service->service_meta) ? $service->service_meta : [];
            $meta['da_snapshot'] = [
                'id' => $snapshot->id,
                'captured_at' => $snapshot->created_at?->toIso8601String() ?? now()->toIso8601String(),
                'dns_record_count' => $imported,
                'mailbox_count' => $counts['mailbox_count'],
                'database_count' => $counts['database_count'],
                'site_count' => $counts['site_count'],
            ];
            $service->update(['service_meta' => $meta]);

            return $snapshot;
        });
    }

    /**
     * A same-day captured snapshot is enough for convert. Re-listing /home/{user}/domains
     * over SSH during the job is how a flaky DA banner fails an already-queued account.
     */
    public function recentCaptured(Service $service, int $maxAgeMinutes = 720): ?DaAccountSnapshot
    {
        $service->loadMissing('latestDaAccountSnapshot');
        $snapshot = $service->latestDaAccountSnapshot;
        if (! $snapshot?->isCaptured()) {
            return null;
        }

        if ($snapshot->created_at && $snapshot->created_at->lt(now()->subMinutes($maxAgeMinutes))) {
            return null;
        }

        return $snapshot;
    }

    /**
     * Capture or throw so convert/queue cannot proceed without DNS on the platform.
     */
    public function captureOrFail(
        Service $service,
        ?DirectAdminCustomerPanelApi $api = null,
        ?User $actor = null,
        bool $reuseRecent = false,
    ): DaAccountSnapshot {
        if ($reuseRecent) {
            $existing = $this->recentCaptured($service);
            if ($existing) {
                return $existing;
            }
        }

        $snapshot = $this->capture($service, $api, $actor);
        if (! $snapshot->isCaptured()) {
            throw new RuntimeException($snapshot->error ?: 'DirectAdmin DNS snapshot failed.');
        }

        return $snapshot;
    }

    /**
     * @return list<string>
     */
    private function hostnamesFromInventory(array $inventory): array
    {
        $names = [];
        $primary = strtolower(trim((string) ($inventory['domain'] ?? '')));
        if ($primary !== '') {
            $names[] = $primary;
        }

        foreach ($inventory['sites'] ?? [] as $site) {
            $name = strtolower(trim((string) ($site['domain'] ?? '')));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return array<string, mixed>
     */
    private function collectZone(DirectAdminCustomerPanelApi $api, string $username, string $hostname): array
    {
        $dns = $this->safeList(fn () => $api->listDnsRecords($username, $hostname));
        $mail = $this->safeList(fn () => $api->listEmailAccounts($username, $hostname));
        $subs = $this->safeList(fn () => $api->listSubdomains($username, $hostname));
        $ftp = $this->safeList(fn () => $api->listFtpAccounts($username, $hostname));
        $ssl = $this->safeList(fn () => $api->getSslInfo($username, $hostname));

        return [
            'hostname' => $hostname,
            'dns_ok' => (bool) ($dns['success'] ?? false),
            'dns_unowned' => false,
            'dns_error' => ($dns['success'] ?? false) ? null : (string) ($dns['message'] ?? 'Failed to list DNS records.'),
            'records' => array_values($dns['data'] ?? []),
            'mailboxes' => array_values($mail['data'] ?? []),
            'subdomains' => array_values($subs['data'] ?? []),
            'ftp' => array_values($ftp['data'] ?? []),
            'ssl' => ($ssl['success'] ?? false) ? ($ssl['data'] ?? []) : ['error' => $ssl['message'] ?? null],
        ];
    }

    /**
     * @return array{success: bool, data: array, message: string}
     */
    private function safeList(callable $callback): array
    {
        try {
            $result = $callback();
        } catch (\Throwable $e) {
            return ['success' => false, 'data' => [], 'message' => $e->getMessage()];
        }

        return [
            'success' => (bool) ($result['success'] ?? false),
            'data' => is_array($result['data'] ?? null) ? $result['data'] : [],
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $zones
     * @return array{site_count: int, database_count: int, mailbox_count: int, ftp_count: int, dns_record_count: int}
     */
    private function counts(array $inventory, array $zones): array
    {
        $mail = 0;
        $ftp = 0;
        $dns = 0;
        foreach ($zones as $zone) {
            $mail += count($zone['mailboxes'] ?? []);
            $ftp += count($zone['ftp'] ?? []);
            $dns += count($zone['records'] ?? []);
        }

        return [
            'site_count' => count($inventory['sites'] ?? []),
            'database_count' => count($inventory['databases'] ?? []),
            'mailbox_count' => $mail,
            'ftp_count' => $ftp,
            'dns_record_count' => $dns,
        ];
    }

    /**
     * @param  array<string, mixed>  $zone
     */
    private function importZone(Service $service, array $zone): int
    {
        $hostname = strtolower((string) ($zone['hostname'] ?? ''));
        if ($hostname === '') {
            return 0;
        }

        $domain = $this->findOrCreateDomain($service, $hostname);
        $dnsZone = DnsZone::query()->firstOrCreate(
            [
                'domain_id' => $domain->id,
                'provider' => self::ZONE_PROVIDER,
            ],
            [
                'name' => $hostname,
                'status' => 'active',
                'service_id' => $service->id,
            ],
        );
        $dnsZone->update([
            'name' => $hostname,
            'status' => 'active',
            'service_id' => $service->id,
        ]);

        $dnsZone->records()->delete();
        $written = 0;
        foreach ($zone['records'] ?? [] as $record) {
            $row = $this->normalizeRecord($record, $hostname);
            if ($row === null) {
                continue;
            }
            DnsRecord::query()->create([
                'dns_zone_id' => $dnsZone->id,
                ...$row,
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array{name: string, type: string, content: string, priority: ?int, ttl: int}|null
     */
    private function normalizeRecord(array $record, string $hostname): ?array
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        $content = trim((string) ($record['value'] ?? $record['content'] ?? ''));
        if ($type === '' || $content === '') {
            return null;
        }

        $name = trim((string) ($record['name'] ?? '@'));
        $name = $name === '' ? '@' : rtrim($name, '.');
        $priority = isset($record['priority']) ? (int) $record['priority'] : null;

        if (in_array($type, ['MX', 'SRV'], true) && $priority === null && preg_match('/^(\d+)\s+(.+)$/', $content, $match)) {
            $priority = (int) $match[1];
            $content = $match[2];
        }

        return [
            'name' => $name === $hostname ? '@' : $name,
            'type' => substr($type, 0, 16),
            'content' => $content,
            'priority' => $priority,
            'ttl' => max(60, (int) ($record['ttl'] ?? 3600)),
        ];
    }

    private function findOrCreateDomain(Service $service, string $hostname): Domain
    {
        $parts = $this->splitHostname($hostname);
        $existing = Domain::query()
            ->whereRaw('LOWER(CONCAT(name, extension)) = ?', [$hostname])
            ->first();

        if ($existing) {
            return $existing;
        }

        return Domain::query()->create([
            'user_id' => $service->user_id,
            'reseller_id' => $service->reseller_id ?? $service->user?->reseller_id,
            'name' => $parts['name'],
            'extension' => $parts['extension'],
            'type' => 'dns',
            'status' => 'active',
            'registrar' => 'external',
            'registered_at' => null,
            'expires_at' => null,
            'auto_renew' => false,
            'cloudflare_dns_enabled' => false,
            'notes' => [
                'source' => 'da_account_snapshot',
                'service_id' => $service->id,
                'imported_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array{name: string, extension: string}
     */
    private function splitHostname(string $hostname): array
    {
        $allowed = DomainExtension::query()->pluck('extension')->all();
        $parsed = $this->parser->parse($hostname, null, $allowed);
        if ($parsed !== null) {
            return $parsed;
        }

        $extensions = collect($allowed)
            ->map(fn (string $ext) => str_starts_with($ext, '.') ? strtolower($ext) : '.'.strtolower($ext))
            ->sortByDesc(fn (string $ext) => strlen($ext))
            ->values();

        foreach ($extensions as $extension) {
            if (str_ends_with($hostname, $extension)) {
                $name = rtrim(substr($hostname, 0, -strlen($extension)), '.');
                if ($name !== '') {
                    return ['name' => $name, 'extension' => $extension];
                }
            }
        }

        $dot = strrpos($hostname, '.');
        if ($dot === false) {
            return ['name' => $hostname, 'extension' => '.com'];
        }

        return [
            'name' => substr($hostname, 0, $dot),
            'extension' => substr($hostname, $dot),
        ];
    }
}
