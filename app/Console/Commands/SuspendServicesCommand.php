<?php

namespace App\Console\Commands;

use App\Services\Provisioning\ProvisioningService;
use App\Services\ServiceOverdueEnforcementService;
use Illuminate\Support\Facades\Log;

class SuspendServicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:suspend-services';

    protected $description = 'Suspends active services with overdue invoices past the grace period';

    protected function handleCron(): string
    {
        $enforcement = app(ServiceOverdueEnforcementService::class);

        if (! $enforcement->isSuspensionEnabled()) {
            return 'Suspension skipped: suspend_on_overdue is disabled.';
        }

        $services = $enforcement->activeServicesWithOverdueInvoicePastGraceQuery()->get();

        $count = 0;
        $provisioningService = app(ProvisioningService::class);

        foreach ($services as $service) {
            try {
                $provisioningService->suspend($service);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to suspend service {$service->id}: {$e->getMessage()}");
            }
        }

        return "Suspended {$count} service(s) past grace period (includes reseller-managed customers).";
    }
}
