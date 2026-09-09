<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisionFailureLedger;
use App\Services\Provisioning\ProvisioningService;

class ProvisionPendingContainersCommand extends BaseCronCommand
{
    protected $signature = 'cron:provision-pending-containers {--limit=25 : Maximum services to attempt}';

    protected $description = 'Auto-provision pending container services, and retry failed ones when the error is transient';

    protected function handleCron(): string
    {
        $invoiceProvisioning = app(InvoiceProvisioningService::class);
        $ledger = app(ProvisionFailureLedger::class);
        $limit = (int) $this->option('limit');

        $services = Service::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where('provisioning_driver_key', 'container')
            ->with('invoice')
            ->orderBy('id')
            ->limit(max($limit * 5, 25))
            ->get();

        $provisioned = [];
        $failed = [];
        $held = [];
        $skipped = 0;

        foreach ($services as $service) {
            if (count($provisioned) + count($failed) >= $limit) {
                break;
            }

            if (! $invoiceProvisioning->invoiceIsPaidEnoughForProvisioning($service)) {
                $skipped++;

                continue;
            }

            if (! $ledger->shouldAutoRetry($service)) {
                $held[] = $service->id;

                continue;
            }

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
        $message .= '. Held '.count($held).' operator-actionable';
        if (count($held) > 0) {
            $message .= ': ['.implode(', ', $held).']';
        }
        $message .= ". Skipped {$skipped} unpaid.";

        \Log::info($message);

        return $message;
    }
}
