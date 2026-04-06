<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Console\Command;

class ProvisionPendingContainersCommand extends Command
{
    protected $signature = 'cron:provision-pending-containers';
    protected $description = 'Auto-provision pending container services';

    public function handle(): int
    {
        $provisioned = [];
        $failed = [];

        // Find pending container services with paid invoices
        $services = Service::where('status', 'pending')
            ->where('provisioning_driver_key', 'container')
            ->with('invoice')
            ->get()
            ->filter(function ($service) {
                // Only provision if invoice is paid
                return $service->invoice && in_array($service->invoice->status, ['paid', 'active']);
            });

        foreach ($services as $service) {
            try {
                $provisioningService = new ProvisioningService();
                $provisioningService->provision($service);
                $provisioned[] = $service->id;
            } catch (\Exception $e) {
                $failed[] = $service->id;
                \Log::error("Failed to provision service {$service->id}: " . $e->getMessage());
            }
        }

        $message = "Provisioned " . count($provisioned) . " containers";
        if (count($provisioned) > 0) {
            $message .= ": [" . implode(', ', $provisioned) . "]";
        }
        $message .= ". Failed " . count($failed);
        if (count($failed) > 0) {
            $message .= ": [" . implode(', ', $failed) . "]";
        }

        $this->info($message);
        \Log::info($message);

        return Command::SUCCESS;
    }
}
