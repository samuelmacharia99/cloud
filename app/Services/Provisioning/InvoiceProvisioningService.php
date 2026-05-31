<?php

namespace App\Services\Provisioning;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InvoiceProvisioningService
{
    public function __construct(
        private ProvisioningService $provisioningService,
    ) {}

    public function shouldAutoProvision(): bool
    {
        $mode = setting('provisioning_mode', 'automatic');
        $autoProvision = setting('auto_provision', 'true');

        if ($mode === 'manual') {
            return false;
        }

        return in_array($autoProvision, ['true', '1', 1, true], true);
    }

    /**
     * @return array{provisioned: int, failed: array<int>, skipped: bool}
     */
    public function provisionPendingServicesForInvoice(Invoice $invoice): array
    {
        if (! $this->shouldAutoProvision()) {
            Log::info('Auto-provisioning skipped by settings', ['invoice_id' => $invoice->id]);

            return ['provisioned' => 0, 'failed' => [], 'skipped' => true];
        }

        if (! in_array($invoice->status, ['paid', 'active'], true)) {
            return ['provisioned' => 0, 'failed' => [], 'skipped' => true];
        }

        $services = $this->resolvePendingServices($invoice);
        $provisioned = 0;
        $failed = [];

        foreach ($services as $service) {
            try {
                if ($service->status !== 'pending') {
                    continue;
                }

                $service->update(['status' => 'provisioning']);
                $this->provisioningService->provision($service->fresh());
                $provisioned++;
            } catch (\Throwable $e) {
                $failed[] = $service->id;
                Log::error('Auto-provisioning failed for service', [
                    'service_id' => $service->id,
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['provisioned' => $provisioned, 'failed' => $failed, 'skipped' => false];
    }

    /**
     * @return Collection<int, Service>
     */
    public function resolvePendingServices(Invoice $invoice)
    {
        $serviceIds = $invoice->items()
            ->whereNotNull('service_id')
            ->pluck('service_id');

        return Service::query()
            ->where(function ($query) use ($invoice, $serviceIds) {
                $query->where('invoice_id', $invoice->id);
                if ($serviceIds->isNotEmpty()) {
                    $query->orWhereIn('id', $serviceIds);
                }
            })
            ->where('status', 'pending')
            ->get()
            ->unique('id')
            ->values();
    }

    public function invoiceIsPaidEnoughForProvisioning(Service $service): bool
    {
        $invoice = $service->invoice;

        if (! $invoice && $service->invoice_id) {
            $invoice = Invoice::find($service->invoice_id);
        }

        if (! $invoice) {
            $invoiceItem = InvoiceItem::where('service_id', $service->id)->with('invoice')->first();
            $invoice = $invoiceItem?->invoice;
        }

        if (! $invoice) {
            return false;
        }

        if ($service->invoice_id !== $invoice->id) {
            $service->update(['invoice_id' => $invoice->id]);
        }

        return in_array($invoice->status, ['paid', 'active'], true);
    }
}
