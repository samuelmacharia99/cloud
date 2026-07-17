<?php

namespace App\Services\Provisioning;

use App\Models\Domain;
use App\Models\Service;
use App\Services\Dns\CloudflareDnsService;
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
                'content' => 'v=spf1 mx a:'.$mailHost.' -all',
                'note' => 'SPF — adjust if you send mail from other hosts',
            ],
            [
                'type' => 'TXT',
                'name' => '_dmarc',
                'content' => (string) config('mailcow.dmarc_policy', 'v=DMARC1; p=none'),
                'note' => 'DMARC starter policy',
            ],
        ];

        $dkim = $client->getDkim($domain);
        if ($dkim['success'] && filled($dkim['dkim_txt'] ?? null)) {
            $selector = (string) ($dkim['selector'] ?? 'dkim');
            $records[] = [
                'type' => 'TXT',
                'name' => $selector.'._domainkey',
                'content' => (string) $dkim['dkim_txt'],
                'note' => 'DKIM from Mailcow',
            ];
        } else {
            $records[] = [
                'type' => 'TXT',
                'name' => 'dkim._domainkey',
                'content' => '(generate DKIM in Mailcow, then refresh this page)',
                'note' => 'DKIM not available yet from API',
            ];
        }

        return $records;
    }

    /**
     * Apply recommended records when the domain is on Talksasa Cloudflare DNS.
     *
     * @return array{success: bool, message: string, applied: list<string>, skipped: list<string>}
     */
    public function applyRecommendedRecords(Service $service): array
    {
        $domainName = $this->provisioning->domainForService($service);
        $domain = Domain::query()
            ->where('user_id', $service->user_id)
            ->get()
            ->first(function (Domain $d) use ($domainName) {
                return strtolower($d->fqdn()) === strtolower($domainName);
            });

        if (! $domain || ! $domain->cloudflare_dns_enabled || ! filled($domain->cloudflare_zone_id)) {
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
                $skipped[] = $record['name'];

                continue;
            }

            $name = $this->absoluteName($record['name'], $domainName);
            $type = strtoupper($record['type']);
            $content = $record['content'];
            $priority = $record['priority'] ?? null;

            $match = collect($existingRecords)->first(function (array $r) use ($type, $name) {
                return strtoupper((string) ($r['type'] ?? '')) === $type
                    && strtolower(rtrim((string) ($r['name'] ?? ''), '.')) === strtolower(rtrim($name, '.'));
            });

            if ($match && ! empty($match['id'])) {
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
                : 'No DNS records were applied.',
            'applied' => $applied,
            'skipped' => $skipped,
        ];
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
}
