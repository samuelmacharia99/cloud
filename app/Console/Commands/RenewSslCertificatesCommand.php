<?php

namespace App\Console\Commands;

use App\Models\ContainerDomain;
use App\Services\Provisioning\NginxProxyService;

class RenewSslCertificatesCommand extends BaseCronCommand
{
    protected $signature = 'cron:renew-ssl-certificates';
    protected $description = 'Renew expiring SSL certificates for container domains';

    public function handleCron(): string
    {
        $renewed = 0;
        $failed = 0;

        // Get all active domains with SSL enabled
        $domains = ContainerDomain::where('ssl_enabled', true)
            ->where('status', 'active')
            ->with('deployment.node')
            ->get();

        $nginxService = new NginxProxyService();

        foreach ($domains as $domain) {
            if (!$domain->deployment || !$domain->deployment->node) {
                $failed++;
                continue;
            }

            try {
                $nginxService->renewSsl($domain);
                $renewed++;
            } catch (\Exception $e) {
                \Log::error("SSL renewal failed for domain {$domain->domain}: " . $e->getMessage());
                $failed++;
            }
        }

        return "Renewed SSL certificates for {$renewed} domains. Failed: {$failed}";
    }
}
