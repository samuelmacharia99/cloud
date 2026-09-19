<?php

namespace App\Console\Commands;

use App\Services\Dns\CloudflareZoneStateService;

/**
 * Ask Cloudflare where each not-yet-live zone stands, and tell the owner when
 * one starts serving.
 *
 * Only zones that are not already live are polled, and a zone leaves that set
 * as soon as it activates, so the work shrinks as domains come good instead of
 * growing with the number of domains on the platform.
 */
class RefreshCloudflareZoneStatusCommand extends BaseCronCommand
{
    protected $signature = 'cron:refresh-cloudflare-zones {--limit=200 : Most zones to check in one run}';

    protected $description = 'Check Cloudflare for zones that are not live yet and notify owners when one activates';

    public function __construct(private CloudflareZoneStateService $zones)
    {
        parent::__construct();
    }

    protected function handleCron(): string
    {
        $limit = max(1, (int) $this->option('limit'));

        $domains = $this->zones->zonesAwaitingActivation()
            ->with('user')
            ->limit($limit)
            ->get();

        $counts = [];
        $failed = 0;

        foreach ($domains as $domain) {
            try {
                $state = $this->zones->refresh($domain);
                $counts[$state['state']] = ($counts[$state['state']] ?? 0) + 1;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn($domain->fqdn().': '.$e->getMessage());
            }
        }

        if ($domains->isEmpty()) {
            return 'No zones awaiting activation.';
        }

        $summary = [];
        foreach ($counts as $state => $count) {
            $summary[] = $count.' '.$state;
        }
        if ($failed > 0) {
            $summary[] = $failed.' failed';
        }

        return 'Checked '.$domains->count().' zone'.($domains->count() === 1 ? '' : 's').': '.implode(', ', $summary).'.';
    }
}
