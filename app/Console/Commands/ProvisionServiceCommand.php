<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProvisionServiceCommand extends Command
{
    protected $signature = 'service:provision {service_id : The ID of the service to provision}';
    protected $description = 'Provision a specific service immediately';

    public function handle(): int
    {
        $serviceId = $this->argument('service_id');

        try {
            $service = Service::find($serviceId);

            if (!$service) {
                $this->error("Service {$serviceId} not found");
                Log::error("Service provisioning failed: Service {$serviceId} not found");
                return Command::FAILURE;
            }

            // Check if service is in a provisioning state
            if (!in_array($service->status, ['pending', 'provisioning'])) {
                $this->warn("Service {$serviceId} is already in status: {$service->status}");
                Log::warning("Service {$serviceId} provisioning skipped: already in status {$service->status}");
                return Command::SUCCESS;
            }

            // Check if invoice is paid
            if (!$service->invoice || !in_array($service->invoice->status, ['paid', 'active'])) {
                $this->error("Service {$serviceId} has no paid invoice");
                Log::error("Service provisioning failed: Service {$serviceId} has no paid invoice");
                return Command::FAILURE;
            }

            // Update status to provisioning
            $service->update(['status' => 'provisioning']);
            $this->info("Service {$serviceId} status updated to: provisioning");

            // Provision the service
            $provisioningService = new ProvisioningService();
            $provisioningService->provision($service);

            // Log success
            $this->info("Service {$serviceId} provisioned successfully");
            Log::info("Service {$serviceId} provisioned successfully", [
                'user_id' => $service->user_id,
                'product_id' => $service->product_id,
                'type' => $service->product->type ?? 'unknown',
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Service provisioning failed: " . $e->getMessage());
            Log::error("Service {$serviceId} provisioning failed", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return Command::FAILURE;
        }
    }
}
