<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProvisionServiceCommand extends Command
{
    protected $signature = 'service:provision {service_id : The ID of the service to provision}';

    protected $description = 'Provision a specific service immediately';

    public function handle(InvoiceProvisioningService $invoiceProvisioning, ProvisioningService $provisioningService): int
    {
        $serviceId = $this->argument('service_id');

        try {
            $service = Service::find($serviceId);

            if (! $service) {
                $this->error("Service {$serviceId} not found");

                return Command::FAILURE;
            }

            if (! in_array($service->status, ['pending', 'provisioning', 'failed'])) {
                $this->warn("Service {$serviceId} is already in status: {$service->status}");

                return Command::SUCCESS;
            }

            if (! $invoiceProvisioning->invoiceIsPaidEnoughForProvisioning($service)) {
                $this->error("Service {$serviceId} has no paid invoice");

                return Command::FAILURE;
            }

            $service->update(['status' => 'provisioning']);
            $provisioningService->provision($service->fresh());

            $this->info("Service {$serviceId} provisioned successfully");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Service provisioning failed: '.$e->getMessage());
            Log::error("Service {$serviceId} provisioning failed", [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }
    }
}
