<?php

namespace App\Console\Commands;

use App\Models\ContainerDomain;
use App\Services\Provisioning\NginxProxyService;
use App\Services\Provisioning\PlatformAppsDomainService;

class RenewSslCertificatesCommand extends BaseCronCommand
{
    protected $signature = 'cron:renew-ssl-certificates';

    protected $description = 'Renew expiring SSL certificates for container domains';

    public function handleCron(): string
    {
        $renewed = 0;
        $failed = 0;

        // Platform hostnames share one wildcard per node; they are renewed
        // below, once per node, not once per hostname.
        $domains = ContainerDomain::where('ssl_enabled', true)
            ->where('status', 'active')
            ->where('purpose', '!=', ContainerDomain::PURPOSE_PLATFORM)
            ->with('deployment.node')
            ->get();

        $nginxService = app(NginxProxyService::class);

        foreach ($domains as $domain) {
            if (! $domain->deployment || ! $domain->deployment->node) {
                $failed++;

                continue;
            }

            try {
                $nginxService->renewSsl($domain);
                $renewed++;
            } catch (\Exception $e) {
                \Log::error("SSL renewal failed for domain {$domain->domain}: ".$e->getMessage());
                $failed++;
            }
        }

        $wildcard = app(PlatformAppsDomainService::class)->renewCertificates();

        return "Renewed SSL certificates for {$renewed} domains. Failed: {$failed}. "
            ."Platform wildcard renewed on {$wildcard['renewed']} node(s), failed on {$wildcard['failed']}.";
    }
}
