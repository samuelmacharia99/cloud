<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Models\Setting;
use App\Services\Provisioning\ProvisioningService;
use App\Services\NotificationService;

class TerminateServicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:terminate-services';
    protected $description = 'Terminates services that have been suspended past the terminate_after_days threshold';

    protected function handleCron(): string
    {
        $terminateDays = (int) Setting::getValue('terminate_after_days', 30);

        $services = Service::where('status', 'suspended')
            ->where('suspend_date', '<=', now()->subDays($terminateDays))
            ->get();

        $count = 0;
        $provisioningService = app(ProvisioningService::class);
        $notificationService = app(NotificationService::class);

        foreach ($services as $service) {
            try {
                // Use provisioning service to terminate
                $provisioningService->terminate($service);
                $notificationService->notifyServiceTerminated($service->fresh());
                $count++;
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Failed to terminate service {$service->id}: {$e->getMessage()}");
            }
        }

        return "Terminated {$count} service(s) after {$terminateDays}-day suspension window.";
    }
}
