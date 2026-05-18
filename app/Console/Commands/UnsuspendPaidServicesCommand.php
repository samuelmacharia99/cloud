<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use App\Services\NotificationService;

class UnsuspendPaidServicesCommand extends BaseCronCommand
{
    protected $signature = 'cron:unsuspend-paid-services';
    protected $description = 'Unsuspends services whose invoices have been paid';

    protected function handleCron(): string
    {
        // Find all suspended services whose invoices are now paid
        $services = Service::with('invoice')
            ->where('status', 'suspended')
            ->whereHas('invoice', function ($q) {
                $q->where('status', 'paid');
            })
            ->get();

        if ($services->isEmpty()) {
            return 'No suspended services to unsuspend.';
        }

        $count = 0;
        $failed = 0;
        $provisioningService = app(ProvisioningService::class);
        $notificationService = app(NotificationService::class);

        foreach ($services as $service) {
            try {
                // Unsuspend the service
                $provisioningService->unsuspend($service);
                
                // Send unsuspension notification
                $notificationService->notifyServiceUnsuspended($service->fresh());
                
                \Log::info("Service unsuspended - invoice paid", [
                    'service_id' => $service->id,
                    'invoice_id' => $service->invoice?->id,
                    'user_id' => $service->user_id,
                ]);
                
                $count++;
            } catch (\Exception $e) {
                $failed++;
                \Log::error("Failed to unsuspend service {$service->id}: {$e->getMessage()}", [
                    'service_id' => $service->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $message = "Unsuspended {$count} service(s)";
        if ($failed > 0) {
            $message .= " ({$failed} failed)";
        }

        return $message;
    }
}
