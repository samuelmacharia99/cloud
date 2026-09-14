<?php

namespace App\Console\Commands;

use App\Models\ContainerDomain;
use App\Services\Provisioning\ContainerGoLiveService;
use App\Services\Provisioning\NginxProxyService;

/**
 * Bound hostnames get their first certificate only if DNS already resolved at
 * bind time, which loses the propagation race. This pass issues it once the A
 * record points at the node, for every recently bound web hostname.
 */
class IssuePendingSslCommand extends BaseCronCommand
{
    protected $signature = 'cron:issue-pending-ssl';

    protected $description = 'Issue first SSL certificates for bound container hostnames whose DNS now points at their node';

    public function handleCron(): string
    {
        $maxAgeDays = max(1, (int) config('containers.go_live.pending_ssl_max_age_days', 14));
        $retryMinutes = max(1, (int) config('containers.go_live.pending_ssl_retry_minutes', 60));

        $domains = ContainerDomain::query()
            ->where('ssl_enabled', false)
            ->whereIn('status', ['active', 'pending'])
            ->where('purpose', ContainerDomain::PURPOSE_WEB)
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->with('deployment.node')
            ->orderBy('id')
            ->get();

        $nginx = app(NginxProxyService::class);
        $goLive = app(ContainerGoLiveService::class);
        $issued = 0;
        $failed = 0;
        $waiting = 0;
        $skipped = 0;

        foreach ($domains as $domain) {
            $nodeIp = (string) ($domain->deployment?->node?->ip_address ?? '');
            if ($nodeIp === '') {
                $skipped++;

                continue;
            }

            // Back off rows that failed recently; certbot rate limits are unforgiving.
            if (filled($domain->error_message) && $domain->updated_at && $domain->updated_at->gt(now()->subMinutes($retryMinutes))) {
                $skipped++;

                continue;
            }

            if (! $nginx->checkDns($domain->domain, $nodeIp)) {
                $waiting++;

                continue;
            }

            if ($goLive->issue($domain)) {
                $issued++;
            } else {
                $failed++;
            }
        }

        return sprintf(
            'Issued %d first certificate(s), %d failed, %d still waiting for DNS, %d skipped (backoff or no node).',
            $issued,
            $failed,
            $waiting,
            $skipped,
        );
    }
}
