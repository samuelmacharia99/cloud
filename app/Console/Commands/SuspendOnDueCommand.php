<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use App\Services\NotificationService;

class SuspendOnDueCommand extends BaseCronCommand
{
    protected $signature = 'cron:suspend-on-due';
    protected $description = 'Suspends active services with invoices due today';

    protected function handleCron(): string
    {
        $today = now()->toDateString();

        // Find all active services whose invoices are due TODAY and not paid
        $services = Service::with('invoice')
            ->where('status', 'active')
            ->whereHas('invoice', function ($q) use ($today) {
                $q->whereIn('status', ['unpaid', 'overdue'])
                  ->where('due_date', '=', $today);
            })
            ->get();

        if ($services->isEmpty()) {
            return 'No services to suspend on due date.';
        }

        $count = 0;
        $failed = 0;
        $provisioningService = app(ProvisioningService::class);
        $notificationService = app(NotificationService::class);

        foreach ($services as $service) {
            try {
                // Suspend the service
                $provisioningService->suspend($service);
                
                // Send suspension notification
                $notificationService->notifyServiceSuspended($service->fresh());
                
                \Log::info("Service suspended on due date", [
                    'service_id' => $service->id,
                    'invoice_id' => $service->invoice?->id,
                    'user_id' => $service->user_id,
                    'due_date' => $today,
                ]);
                
                $count++;
            } catch (\Exception $e) {
                $failed++;
                \Log::error("Failed to suspend service on due date {$service->id}: {$e->getMessage()}", [
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
