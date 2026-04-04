<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\ProvisioningService;
use App\Services\NotificationService;

class SuspendServicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:suspend-services';
    protected $description = 'Suspends active services with overdue invoices past the grace period';

    protected function handleCron(): string
    {
        $suspendOnOverdue = Setting::getValue('suspend_on_overdue', 'false');
        if ($suspendOnOverdue !== 'true') {
            return 'Suspension skipped: suspend_on_overdue is disabled.';
        }

        $graceDays = (int) Setting::getValue('grace_period_days', 3);

        $services = Service::with('invoice')
            ->where('status', 'active')
            ->whereHas('invoice', function ($q) use ($graceDays) {
                $q->where('status', 'overdue')
                  ->where('due_date', '<', now()->subDays($graceDays)->toDateString());
            })
            ->get();

        $count = 0;
        $provisioningService = app(ProvisioningService::class);
        $notificationService = app(NotificationService::class);

        foreach ($services as $service) {
            try {
                // Use provisioning service to suspend
                $provisioningService->suspend($service);
                $notificationService->notifyServiceSuspended($service->fresh());
                $count++;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to suspend service {$service->id}: {$e->getMessage()}");
            }
        }

        return "Suspended {$count} service(s) past grace period.";
    }
}
