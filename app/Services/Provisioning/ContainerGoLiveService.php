<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDomain;
use App\Models\Service;
use App\Services\Dns\DomainCloudflareDnsService;
use Illuminate\Support\Facades\Log;

/**
 * After a hostname is bound to a container, get the site live: explain who
 * controls its DNS, wait a bounded time for a platform-managed A record to
 * propagate, and issue the first certificate as soon as it resolves. External
 * DNS gets a clear instruction instead; the scheduler finishes SSL later.
 */
class ContainerGoLiveService
{
    public function __construct(
        private DomainCloudflareDnsService $dns,
        private NginxProxyService $nginx,
        private ContainerDomainBindingService $binding,
    ) {}

    /**
     * @param  callable(string): void  $step  operator-facing progress line
     * @return array{managed: bool, hosts: list<string>, resolved: list<string>, ssl: array<string, bool>, node_ip: string}
     */
    public function goLive(Service $service, string $hostname, callable $step, ?int $waitSeconds = null): array
    {
        $hostname = strtolower(trim($hostname));
        $service->loadMissing('containerDeployment.node', 'containerDeployment.domains');
        $deployment = $service->containerDeployment;
        $nodeIp = (string) ($deployment?->node?->ip_address ?? '');
        $hosts = $this->binding->hostnamesFor($hostname);
        $result = ['managed' => false, 'hosts' => $hosts, 'resolved' => [], 'ssl' => [], 'node_ip' => $nodeIp];

        if ($hostname === '' || $nodeIp === '' || ! $deployment) {
            $step('No node address for '.$hostname.'; DNS and SSL are left for later.');

            return $result;
        }

        $managedDomain = $this->dns->resolvePlatformDomainForHostname((int) $service->user_id, $hostname);
        $result['managed'] = $managedDomain !== null;

        if ($managedDomain) {
            $step(sprintf(
                'DNS for %s is managed by Talksasa (zone %s): A records for %s now point at %s',
                $hostname,
                $managedDomain->fqdn(),
                implode(' and ', $hosts),
                $nodeIp,
            ));
        } else {
            $step(sprintf(
                'DNS for %s is external: point %s at %s (A records) to go live. SSL is issued automatically within minutes of that.',
                $hostname,
                implode(' and ', $hosts),
                $nodeIp,
            ));
        }

        $wait = $managedDomain
            ? max(0, $waitSeconds ?? (int) config('containers.go_live.dns_wait_seconds', 180))
            : 0;
        $deadline = time() + $wait;
        $pending = $hosts;

        do {
            foreach ($pending as $index => $host) {
                if ($this->nginx->checkDns($host, $nodeIp)) {
                    $result['resolved'][] = $host;
                    unset($pending[$index]);
                }
            }
            if ($pending === [] || time() >= $deadline) {
                break;
            }
            $step(sprintf('Waiting for %s to resolve to %s (up to %ds)…', implode(', ', $pending), $nodeIp, max(0, $deadline - time())));
            sleep(min(10, max(1, $deadline - time())));
        } while (true);

        if ($result['resolved'] === []) {
            $step($managedDomain
                ? sprintf('%s has not propagated yet; the scheduler issues SSL as soon as it resolves.', implode(' and ', $hosts))
                : 'SSL waits until the A records point here.');

            return $result;
        }

        foreach ($result['resolved'] as $host) {
            $domain = $deployment->domains->first(fn (ContainerDomain $d) => strtolower((string) $d->domain) === $host);
            if (! $domain) {
                continue;
            }
            if ($domain->ssl_enabled) {
                $result['ssl'][$host] = true;
                $step('https://'.$host.' already has a certificate');

                continue;
            }
            $result['ssl'][$host] = $this->issue($domain, $step);
        }

        $live = array_keys(array_filter($result['ssl']));
        if ($live !== []) {
            $step('Live with HTTPS: '.implode(', ', array_map(fn ($h) => 'https://'.$h, $live)));
        }

        return $result;
    }

    /**
     * Issue the first certificate for one bound hostname. Never throws.
     */
    public function issue(ContainerDomain $domain, ?callable $step = null): bool
    {
        $report = static function (string $line) use ($step): void {
            if ($step) {
                $step($line);
            }
        };

        try {
            $report('Issuing certificate for '.$domain->domain);
            $this->nginx->enableSsl($domain);
            $report('https://'.$domain->domain.' is live');

            return true;
        } catch (\Throwable $e) {
            Log::info('First certificate issue failed', [
                'domain' => $domain->domain,
                'error' => $e->getMessage(),
            ]);
            $report('Certificate for '.$domain->domain.' failed: '.$e->getMessage().' The scheduler retries.');

            return false;
        }
    }
}
