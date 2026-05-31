<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ResellerSslService;

class ProvisionResellerSslCommand extends BaseCronCommand
{
    protected $signature = 'cron:provision-reseller-ssl';

    protected $description = 'Queue SSL provisioning and renewal for reseller custom domains';

    public function handleCron(): string
    {
        $sslService = app(ResellerSslService::class);
        $queued = 0;
        $skipped = 0;

        $resellers = User::query()
            ->where('is_reseller', true)
            ->whereNotNull('settings')
            ->get()
            ->filter(function (User $reseller) use ($sslService) {
                $domain = $reseller->settings['branding']['custom_domain'] ?? null;

                return ! empty($domain) && $sslService->shouldProcess($reseller);
            });

        foreach ($resellers as $reseller) {
            if ($sslService->queueProvision($reseller, 'scheduled')) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        return "Queued SSL jobs for {$queued} reseller domain(s). Skipped: {$skipped}.";
    }
}
