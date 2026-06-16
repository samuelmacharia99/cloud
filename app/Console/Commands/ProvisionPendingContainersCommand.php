<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;

class ProvisionPendingContainersCommand extends BaseCronCommand
{
    protected $signature = 'cron:provision-pending-containers';

    protected $description = 'Auto-provision pending container services';

    protected function handleCron(): string
    {
        $provisioned = [];
        $failed = [];

        $services = Service::where('status', 'pending')
            ->where('provisioning_driver_key', 'container')
            ->with('invoice')
            ->get()
            ->filter(function ($service) {
                return $service->invoice && in_array($service->invoice->status, ['paid', 'active']);
            });

        foreach ($services as $service) {
            try {
                app(ProvisioningService::class)->provision($service);
                $provisioned[] = $service->id;
            } catch (\Exception $e) {
                $failed[] = $service->id;
                \Log::error("Failed to provision service {$service->id}: ".$e->getMessage());
            }
        }

        $message = 'Provisioned '.count($provisioned).' containers';
        if (count($provisioned) > 0) {
            $message .= ': ['.implode(', ', $provisioned).']';
        }
        $message .= '. Failed '.count($failed);
        if (count($failed) > 0) {
            $message .= ': ['.implode(', ', $failed).']';
        }

        \Log::info($message);

        return $message;
    }
}
