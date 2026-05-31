<?php

namespace App\Console\Commands;

use App\Models\Service;
use App\Services\Provisioning\InvoiceProvisioningService;
use App\Services\Provisioning\ProvisioningService;
use Illuminate\Console\Command;

class ProvisionPendingDirectAdminCommand extends Command
{
    protected $signature = 'directadmin:provision-pending {--limit=25 : Maximum services to attempt}';

    protected $description = 'Retry provisioning for pending or failed DirectAdmin shared hosting services with paid invoices';

    public function handle(
        InvoiceProvisioningService $invoiceProvisioning,
        ProvisioningService $provisioningService,
    ): int {
        $limit = (int) $this->option('limit');

        $services = Service::query()
            ->whereIn('status', ['pending', 'failed'])
            ->where(function ($query) {
                $query->where('provisioning_driver_key', 'directadmin')
                    ->orWhereHas('product', fn ($product) => $product->where('provisioning_driver_key', 'directadmin'));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $attempted = 0;
        $provisioned = 0;

        foreach ($services as $service) {
            if (! $invoiceProvisioning->invoiceIsPaidEnoughForProvisioning($service)) {
                continue;
            }

            $attempted++;

            try {
                $service->update(['status' => 'provisioning']);
                $provisioningService->provision($service->fresh());
                $provisioned++;
            } catch (\Throwable $e) {
                $this->warn("Service {$service->id} failed: {$e->getMessage()}");
            }
        }

        $this->info("Attempted {$attempted} DirectAdmin services, provisioned {$provisioned}.");

        return Command::SUCCESS;
    }
}
