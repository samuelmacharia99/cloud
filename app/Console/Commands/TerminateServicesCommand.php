<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Provisioning\ProvisioningService;
use App\Services\ServiceOverdueEnforcementService;
use Illuminate\Support\Facades\Log;

class TerminateServicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:terminate-services';

    protected $description = 'Terminates services whose invoice has remained unpaid for the configured number of months';

    protected function handleCron(): string
    {
        $unpaidMonths = (int) Setting::getValue('terminate_after_unpaid_months', 4);

        if ($unpaidMonths < 1) {
            return 'Termination skipped: terminate_after_unpaid_months must be at least 1.';
        }

        $cutoffDate = now()->subMonths($unpaidMonths)->startOfDay();
        $services = app(ServiceOverdueEnforcementService::class)
            ->servicesWithUnpaidInvoiceOnOrBeforeQuery($cutoffDate)
            ->get();

        $count = 0;
        $provisioningService = app(ProvisioningService::class);

        foreach ($services as $service) {
            try {
                $provisioningService->terminate($service);
                $count++;
            } catch (\Exception $e) {
                Log::error("Failed to terminate service {$service->id}: {$e->getMessage()}");
            }
        }

        return "Terminated {$count} service(s) with invoices unpaid for {$unpaidMonths}+ month(s).";
    }
}
