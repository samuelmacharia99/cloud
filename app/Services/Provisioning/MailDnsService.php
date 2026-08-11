<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\Service;
use App\Services\Dns\CloudflareDnsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * MX / SPF / DKIM / DMARC helpers for Mailcow email domains.
 */
class MailDnsService
{
    public function __construct(
        private CloudflareDnsService $cloudflare,
        private MailcowProvisioningService $provisioning,
    ) {}

    /**
     * Recommended DNS records (always available for copy-paste).
     * Ensures DKIM exists in Mailcow when the API is reachable.
     *
     * @return list<array{type: string, name: string, content: string, priority?: int, note?: string}>
     */
    public function recommendedRecords(Service $service): array
    {
        $client = $this->provisioning->clientForService($service);
        $domain = $this->provisioning->domainForService($service);
        $mailHost = $client->mailHostname();

        $records = [
            [
                'type' => 'MX',
                'name' => '@',
                'content' => $mailHost,
                'priority' => 10,
                'note' => 'Primary mail exchanger',
            ],
            [
                'type' => 'TXT',
                'name' => '@',
                'content' => $this->spfRecord($client),
                'note' => 'SPF — authorize Mailcow; keep -all strict',
            ],
            [
                'type' => 'TXT',
                'name' => '_dmarc',
                'content' => $this->dmarcRecord($domain),
                'note' => 'DMARC policy (override via MAILCOW_DMARC_POLICY)',
            ],
        ];

        $dkim = $client->ensureDkim($domain);
        if ($dkim['success'] && filled($dkim['dkim_txt'] ?? null)) {
            $selector = (string) ($dkim['selector'] ?? config('mailcow.dkim_selector', 'dkim'));
            $records[] = [
                'type' => 'TXT',
                'name' => $selector.'._domainkey',
                'content' => (string) $dkim['dkim_txt'],
                'note' => ($dkim['created'] ?? false) ? 'DKIM generated in Mailcow' : 'DKIM from Mailcow',
            ];
        } else {
            $records[] = [
                'type' => 'TXT',
                'name' => ((string) config('mailcow.dkim_selector', 'dkim')).'._domainkey',
                'content' => '(generate DKIM in Mailcow, then refresh this page)',
                'note' => 'DKIM not available yet: '.($dkim['message'] ?? 'API error'),
            ];
        }

        return $records;
    }

    /**
     * Live DNS auth checks (MX / SPF / DKIM / DMARC).
     *
     * @return array{
     *   mx_ok: ?bool,
     *   spf_ok: ?bool,
     *   dkim_ok: ?bool,
     *   dmarc_ok: ?bool,
     *   dns_ok: ?bool,
     *   dns_note: string,
     *   checks: array{mx: string, spf: string, dkim: string, dmarc: string},
     *   ptr_note: string
     * }
     */
    public function deliverabilityHealth(Service $service): array
    {
        $checks = [
            'mx' => 'Not checked',
            'spf' => 'Not checked',
            'dkim' => 'Not checked',
            'dmarc' => 'Not checked',
        ];
        $mxOk = $spfOk = $dkimOk = $dmarcOk = null;
        $ptrNote = 'PTR (reverse DNS) for the mail server IP must be set by the host operator — not via Cloudflare zone records.';

        try {
            $client = $this->provisioning->clientForService($service);
            $domain = $this->provisioning->domainForService($service);
            $mailHost = strtolower($client->mailHostname());
            $selector = (string) config('mailcow.dkim_selector', 'dkim');

            $dkim = $client->getDkim($domain);
            if (($dkim['success'] ?? false) && filled($dkim['selector'] ?? null)) {
                $selector = (string) $dkim['selector'];
            }
            $expectedDkim = (string) ($dkim['dkim_txt'] ?? '');

            $mxRecords = @dns_get_record($domain, DNS_MX) ?: [];
            $mxTargets = collect($mxRecords)
                ->pluck('target')
                ->map(fn ($t) => strtolower(rtrim((string) $t, '.')));
            if ($mxTargets->isEmpty()) {
                $mxOk = false;
                $checks['mx'] = 'No MX records found';
            } elseif ($mxTargets->contains($mailHost)) {
                $mxOk = true;
                $checks['mx'] = 'MX points to '.$mailHost;
            } else {
                $mxOk = false;
                $checks['mx'] = 'MX does not point to '.$mailHost;
            }

            $txtRecords = @dns_get_record($domain, DNS_TXT) ?: [];
            $txtValues = collect($txtRecords)
                ->map(fn ($r) => $this->normalizeDnsTxt((string) ($r['txt'] ?? $r['text'] ?? '')))
                ->filter();

            $spf = $txtValues->first(fn (string $v) => str_starts_with(strtolower($v), 'v=spf1'));
            if ($spf === null) {
                $spfOk = false;
                $checks['spf'] = 'No SPF TXT at apex';
            } else {
                $spfLower = strtolower($spf);
                $authorizesMail = str_contains($spfLower, 'mx')
                    || str_contains($spfLower, 'a:'.$mailHost)
                    || ($client->mailIpAddress() && str_contains($spfLower, 'ip4:'.$client->mailIpAddress()));
                $spfOk = $authorizesMail;
                $checks['spf'] = $spfOk
                    ? 'SPF present and authorizes Mailcow'
                    : 'SPF present but may not authorize '.$mailHost;
            }

            $dkimHost = $selector.'._domainkey.'.$domain;
            $dkimRecords = @dns_get_record($dkimHost, DNS_TXT) ?: [];
            $dkimLive = collect($dkimRecords)
                ->map(fn ($r) => $this->normalizeDnsTxt((string) ($r['txt'] ?? $r['text'] ?? '')))
                ->filter()
                ->implode('');
            if ($dkimLive === '') {
                $dkimOk = false;
                $checks['dkim'] = 'No DKIM TXT at '.$selector.'._domainkey';
            } elseif ($expectedDkim !== '' && ! $this->dkimPubkeysMatch($dkimLive, $expectedDkim)) {
                $dkimOk = false;
                $checks['dkim'] = 'DKIM TXT does not match Mailcow key';
            } else {
                $dkimOk = str_contains(strtolower($dkimLive), 'v=dkim1') || str_contains(strtolower($dkimLive), 'p=');
                $checks['dkim'] = $dkimOk ? 'DKIM published at '.$selector.'._domainkey' : 'DKIM TXT malformed';
            }

            $dmarcHost = '_dmarc.'.$domain;
            $dmarcRecords = @dns_get_record($dmarcHost, DNS_TXT) ?: [];
            $dmarcLive = collect($dmarcRecords)
                ->map(fn ($r) => $this->normalizeDnsTxt((string) ($r['txt'] ?? $r['text'] ?? '')))
                ->first(fn (string $v) => str_starts_with(strtolower($v), 'v=dmarc1'));
            if ($dmarcLive === null) {
                $dmarcOk = false;
                $checks['dmarc'] = 'No DMARC TXT at _dmarc';
            } else {
                $dmarcOk = true;
                $checks['dmarc'] = 'DMARC present';
            }
        } catch (\Throwable $e) {
            return [
                'mx_ok' => null,
                'spf_ok' => null,
                'dkim_ok' => null,
                'dmarc_ok' => null,
                'dns_ok' => null,
                'dns_note' => 'Could not verify DNS',
                'checks' => $checks,
                'ptr_note' => $ptrNote,
            ];
        }

        $known = array_filter([$mxOk, $spfOk, $dkimOk, $dmarcOk], fn ($v) => $v !== null);
        $dnsOk = $known === [] ? null : ! in_array(false, $known, true);

        $failed = [];
        if ($mxOk === false) {
            $failed[] = 'MX';
        }
        if ($spfOk === false) {
            $failed[] = 'SPF';
        }
        if ($dkimOk === false) {
            $failed[] = 'DKIM';
        }
        if ($dmarcOk === false) {
            $failed[] = 'DMARC';
        }

        $dnsNote = $dnsOk === true
            ? 'MX, SPF, DKIM, and DMARC look good'
            : ($failed !== [] ? 'Needs attention: '.implode(', ', $failed) : 'DNS not fully checked');

        return [
            'mx_ok' => $mxOk,
            'spf_ok' => $spfOk,
            'dkim_ok' => $dkimOk,
            'dmarc_ok' => $dmarcOk,
            'dns_ok' => $dnsOk,
            'dns_note' => $dnsNote,
            'checks' => $checks,
            'ptr_note' => $ptrNote,
        ];
    }

    /**
     * Apply recommended records when the domain is on Talksasa Cloudflare DNS.
     *
     * @return array{success: bool, message: string, applied: list<string>, skipped: list<string>}
     */
    public function applyRecommendedRecords(Service $service): array
    {
        $domainName = $this->provisioning->domainForService($service);
        $domain = $this->findCloudflareDomainForService($service, $domainName);

        if (! $domain) {
            return [
                'success' => false,
                'message' => 'Domain is not using Talksasa Cloudflare DNS. Copy the records manually.',
                'applied' => [],
                'skipped' => ['cloudflare'],
            ];
        }

        if (! $this->cloudflare->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Cloudflare DNS is not configured on the platform.',
                'applied' => [],
                'skipped' => ['config'],
            ];
        }

        $zoneId = (string) $domain->cloudflare_zone_id;
        $existing = $this->cloudflare->listRecords($zoneId);
        $existingRecords = $existing['success'] ? ($existing['records'] ?? []) : [];

        $applied = [];
        $skipped = [];

        foreach ($this->recommendedRecords($service) as $record) {
            if (str_contains((string) $record['content'], 'generate DKIM')) {
                $skipped[] = $record['name'].' (DKIM unavailable from Mailcow)';

                continue;
            }

            $name = $this->absoluteName($record['name'], $domainName);
            $type = strtoupper($record['type']);
            $content = $record['content'];
            $priority = $record['priority'] ?? null;

            $match = $this->findMatchingRecord($existingRecords, $type, $name, $content);

            if ($match && ! empty($match['id'])) {
                $existingContent = $this->normalizeDnsTxt((string) ($match['content'] ?? ''));
                $wantContent = $this->normalizeDnsTxt($content);
                $samePriority = ! isset($priority)
                    || (int) ($match['priority'] ?? 0) === (int) $priority;

                if (strcasecmp($existingContent, $wantContent) === 0 && $samePriority) {
                    $applied[] = $type.' '.$record['name'].' (unchanged)';

                    continue;
                }

                $result = $this->cloudflare->updateRecord(
                    $zoneId,
                    (string) $match['id'],
                    $type,
                    $name,
                    $content,
                    3600,
                    $priority
                );
            } else {
                $result = $this->cloudflare->createRecord(
                    $zoneId,
                    $type,
                    $name,
                    $content,
                    3600,
                    $priority
                );
            }

            if ($result['success']) {
                $applied[] = $type.' '.$record['name'];
            } else {
                $skipped[] = $type.' '.$record['name'].': '.($result['message'] ?? 'failed');
                Log::warning('Mail DNS apply failed', [
                    'service_id' => $service->id,
                    'record' => $record,
                    'error' => $result['message'] ?? null,
                ]);
            }
        }

        return [
            'success' => $applied !== [],
            'message' => $applied !== []
                ? 'Applied '.count($applied).' DNS record(s) via Cloudflare.'
                    .($skipped !== [] ? ' Some records skipped: '.implode('; ', $skipped) : '')
                : ($skipped !== []
                    ? 'No DNS records were applied: '.implode('; ', $skipped)
                    : 'No DNS records were applied.'),
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    /**
     * Re-apply mail DNS for every active email hosting service linked to this domain.
     */
    public function applyForDomain(Domain $domain): void
    {
        foreach ($this->emailServicesForDomain($domain) as $service) {
            if (! $this->serviceIsActive($service)) {
                continue;
            }

            try {
                $this->applyRecommendedRecords($service);
            } catch (\Throwable $e) {
                Log::info('Mail DNS re-apply after zone provision skipped', [
                    'service_id' => $service->id,
                    'domain_id' => $domain->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sync hardened mail DNS for all active Mailcow services on Talksasa Cloudflare DNS.
     *
     * @return array{
     *   scanned: int,
     *   eligible: int,
     *   applied: int,
     *   failed: int,
     *   skipped: int,
     *   results: list<array{service_id: int, domain: string, status: string, message: string}>
     * }
     */
    public function syncAllCloudflareMailDomains(bool $dryRun = false, ?int $limit = null): array
    {
        $services = Service::query()
            ->with(['product', 'node', 'user'])
            ->where(function ($query) {
                $query->where('provisioning_driver_key', 'mailcow')
                    ->orWhereHas('product', fn ($product) => $product->where('provisioning_driver_key', 'mailcow')
                        ->orWhere('type', 'email_hosting'));
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Service $service) => $service->isEmailHosting() && $this->serviceIsActive($service));

        $results = [];
        $eligible = 0;
        $applied = 0;
        $failed = 0;
        $skipped = 0;
        $scanned = $services->count();

        foreach ($services as $service) {
            if ($limit !== null && $eligible >= $limit) {
                break;
            }

            try {
                $domainName = $this->provisioning->domainForService($service);
            } catch (\Throwable $e) {
                $skipped++;
                $results[] = [
                    'service_id' => $service->id,
                    'domain' => '',
                    'status' => 'skipped',
                    'message' => $e->getMessage(),
                ];

                continue;
            }

            $domain = $this->findCloudflareDomainForService($service, $domainName);
            if (! $domain) {
                $skipped++;
                $results[] = [
                    'service_id' => $service->id,
                    'domain' => $domainName,
                    'status' => 'skipped',
                    'message' => 'Not on Talksasa Cloudflare DNS',
                ];

                continue;
            }

            $eligible++;

            if ($dryRun) {
                $results[] = [
                    'service_id' => $service->id,
                    'domain' => $domainName,
                    'status' => 'dry-run',
                    'message' => 'Would ensure DKIM and apply MX/SPF/DKIM/DMARC',
                ];

                continue;
            }

            try {
                $result = $this->applyRecommendedRecords($service);
                if ($result['success'] || ($result['applied'] ?? []) !== []) {
                    $applied++;
                    $results[] = [
                        'service_id' => $service->id,
                        'domain' => $domainName,
                        'status' => 'applied',
                        'message' => $result['message'],
                    ];
                } else {
                    $failed++;
                    $results[] = [
                        'service_id' => $service->id,
                        'domain' => $domainName,
                        'status' => 'failed',
                        'message' => $result['message'].($result['skipped'] !== [] ? ' ['.implode('; ', $result['skipped']).']' : ''),
                    ];
                }
            } catch (\Throwable $e) {
                $failed++;
                $results[] = [
                    'service_id' => $service->id,
                    'domain' => $domainName,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
                Log::warning('Mail Cloudflare DNS sync failed', [
                    'service_id' => $service->id,
                    'domain' => $domainName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'scanned' => $scanned,
            'eligible' => $eligible,
            'applied' => $dryRun ? 0 : $applied,
            'failed' => $failed,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    public function spfRecord(MailcowService $client): string
    {
        $parts = ['v=spf1', 'mx', 'a:'.$client->mailHostname()];
        $ip = $client->mailIpAddress();
        if ($ip) {
            $parts[] = 'ip4:'.$ip;
        }
        $parts[] = '-all';

        return implode(' ', $parts);
    }

    public function dmarcRecord(string $domain): string
    {
        $policy = (string) config('mailcow.dmarc_policy', 'v=DMARC1; p=quarantine; adkim=r; aspf=r');
        $policy = str_replace('{domain}', strtolower($domain), $policy);

        return $policy;
    }

    /**
     * @return Collection<int, Service>
     */
    private function emailServicesForDomain(Domain $domain)
    {
        $fqdn = strtolower($domain->fqdn());

        return Service::query()
            ->where('user_id', $domain->user_id)
            ->with(['product', 'node', 'user'])
            ->get()
            ->filter(function (Service $service) use ($domain, $fqdn) {
                if (! $service->isEmailHosting()) {
                    return false;
                }

                $meta = is_array($service->service_meta) ? $service->service_meta : [];
                if (! empty($meta['domain_id']) && (int) $meta['domain_id'] === (int) $domain->id) {
                    return true;
                }

                $mailDomain = strtolower((string) ($meta['mailcow_domain'] ?? $meta['domain'] ?? $service->external_reference ?? ''));

                return $mailDomain !== '' && $mailDomain === $fqdn;
            });
    }

    private function findCloudflareDomainForService(Service $service, string $domainName): ?Domain
    {
        return Domain::query()
            ->where('user_id', $service->user_id)
            ->get()
            ->first(function (Domain $d) use ($domainName) {
                return strtolower($d->fqdn()) === strtolower($domainName)
                    && $d->cloudflare_dns_enabled
                    && filled($d->cloudflare_zone_id);
            });
    }

    private function serviceIsActive(Service $service): bool
    {
        $status = $service->status;

        if ($status instanceof ServiceStatus) {
            return $status === ServiceStatus::Active;
        }

        return (string) $status === 'active';
    }

    /**
     * @param  list<array<string, mixed>>  $existingRecords
     * @return array<string, mixed>|null
     */
    private function findMatchingRecord(array $existingRecords, string $type, string $name, string $content): ?array
    {
        $normalizedName = strtolower(rtrim($name, '.'));
        $wantTxt = $this->normalizeDnsTxt($content);
        $wantLower = strtolower($wantTxt);

        return collect($existingRecords)->first(function (array $r) use ($type, $normalizedName, $wantLower) {
            if (strtoupper((string) ($r['type'] ?? '')) !== $type) {
                return false;
            }

            if (strtolower(rtrim((string) ($r['name'] ?? ''), '.')) !== $normalizedName) {
                return false;
            }

            // Apex can have many TXT records — only match SPF to SPF.
            if ($type === 'TXT') {
                $existingTxt = strtolower($this->normalizeDnsTxt((string) ($r['content'] ?? '')));
                if (str_starts_with($wantLower, 'v=spf1')) {
                    return str_starts_with($existingTxt, 'v=spf1');
                }
                if (str_starts_with($wantLower, 'v=dmarc1')) {
                    return str_starts_with($existingTxt, 'v=dmarc1');
                }
                if (str_starts_with($wantLower, 'v=dkim1') || str_contains($wantLower, 'p=')) {
                    return str_starts_with($existingTxt, 'v=dkim1') || str_contains($existingTxt, 'p=');
                }
            }

            return true;
        });
    }

    private function absoluteName(string $relative, string $domain): string
    {
        $relative = trim($relative);
        if ($relative === '@' || $relative === '') {
            return $domain;
        }

        if (str_ends_with(strtolower($relative), '.'.strtolower($domain))) {
            return $relative;
        }

        return $relative.'.'.$domain;
    }

    private function normalizeDnsTxt(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_contains($value, '" "')) {
            $value = str_replace('" "', '', $value);
        }

        return trim($value, "\" \t\n\r");
    }

    private function dkimPubkeysMatch(string $live, string $expected): bool
    {
        $extract = static function (string $txt): string {
            if (preg_match('/p=([A-Za-z0-9+\/=]+)/i', $txt, $m)) {
                return strtolower(rtrim($m[1], '='));
            }

            return strtolower(preg_replace('/\s+/', '', $txt) ?? '');
        };

        $a = $extract($this->normalizeDnsTxt($live));
        $b = $extract($this->normalizeDnsTxt($expected));

        return $a !== '' && $a === $b;
    }
}
