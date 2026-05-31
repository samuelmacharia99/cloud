<?php

namespace App\Console\Commands;

use App\Services\Provisioning\ProvisioningService;
use App\Services\ServiceOverdueEnforcementService;
use Illuminate\Support\Facades\Log;

class SuspendOnDueCommand extends BaseCronCommand
{
    protected $signature = 'cron:suspend-on-due';

    protected $description = 'Suspends active services with invoices due today';

    protected function handleCron(): string
    {
        $enforcement = app(ServiceOverdueEnforcementService::class);

        if (! $enforcement->isSuspensionEnabled()) {
            return 'Suspension skipped: suspend_on_overdue is disabled.';
        }

        $today = now()->startOfDay();
        $services = $enforcement->activeServicesWithUnpaidInvoiceDueOnQuery($today)->get();

        if ($services->isEmpty()) {
            return 'No services to suspend on due date.';
        }

        $count = 0;
        $failed = 0;
        $provisioningService = app(ProvisioningService::class);

        foreach ($services as $service) {
            try {
                $provisioningService->suspend($service);

                Log::info('Service suspended on due date', [
                    'service_id' => $service->id,
                    'invoice_id' => $service->invoice?->id,
                    'user_id' => $service->user_id,
                    'reseller_id' => $service->reseller_id,
                    'due_date' => $today->toDateString(),
                ]);

                $count++;
            } catch (\Exception $e) {
                $failed++;
                Log::error("Failed to suspend service on due date {$service->id}: {$e->getMessage()}", [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = "Suspended {$count} service(s) on their due date";
        if ($failed > 0) {
            $message .= " ({$failed} failed)";
        }

        return $message;
    }
}
