<?php

namespace App\Services\Provisioning;

use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use App\Services\ResellerEnforcementService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class InvoiceProvisioningService
{
    private const RESELLER_HOSTING_TYPES = ['shared_hosting', 'container_hosting'];

    public function __construct(
        private ProvisioningService $provisioningService,
        private ResellerEnforcementService $resellerEnforcement,
    ) {}

    public function shouldAutoProvision(?Invoice $invoice = null): bool
    {
        if ($this->isManualProvisioningMode()) {
            return false;
        }

        if ($this->isTruthySetting('auto_provision', 'true')) {
            return true;
        }

        if ($invoice && $this->isResellerManagedHostingInvoice($invoice)) {
            return $this->resellerHostingAutoProvisionEnabled();
        }

        return false;
    }

    public function shouldAutoProvisionService(Service $service): bool
    {
        if ($this->isManualProvisioningMode()) {
            return false;
        }

        if ($this->isTruthySetting('auto_provision', 'true')) {
            return true;
        }

        if ($this->isResellerManagedHostingService($service)) {
            return $this->resellerHostingAutoProvisionEnabled();
        }

        return false;
    }

    /**
     * @return array{provisioned: int, failed: array<int>, skipped: bool}
     */
    public function provisionPendingServicesForInvoice(Invoice $invoice): array
    {
        if (! $this->shouldAutoProvision($invoice)) {
            Log::info('Auto-provisioning skipped by settings', ['invoice_id' => $invoice->id]);

            return ['provisioned' => 0, 'failed' => [], 'skipped' => true];
        }

        $status = $invoice->status instanceof \BackedEnum
            ? $invoice->status->value
            : (string) $invoice->status;

        if (! in_array($status, ['paid', 'active'], true)) {
            return ['provisioned' => 0, 'failed' => [], 'skipped' => true];
        }

        $services = $this->resolvePendingServices($invoice);
        $provisioned = 0;
        $failed = [];

        foreach ($services as $service) {
            try {
                if ($service->status !== ServiceStatus::Pending) {
                    continue;
                }

                if (! $this->shouldProvisionService($service, $invoice)) {
                    continue;
                }

                $this->resellerEnforcement->assertCanProvision($service);

                $service->update(['status' => ServiceStatus::Provisioning]);
                $this->provisioningService->provision($service->fresh());
                $provisioned++;
            } catch (\Throwable $e) {
                $failed[] = $service->id;
                $meta = $service->service_meta ?? [];
                $meta['provision_error'] = $e->getMessage();
                $meta['provision_failed_at'] = now()->toIso8601String();

                $service->update([
                    'status' => ServiceStatus::Failed,
                    'service_meta' => $meta,
                ]);

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
            ->with(['product', 'user'])
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

        $status = $invoice->status instanceof \BackedEnum
            ? $invoice->status->value
            : (string) $invoice->status;

        return in_array($status, ['paid', 'active'], true);
    }

    private function shouldProvisionService(Service $service, Invoice $invoice): bool
    {
        if ($this->isTruthySetting('auto_provision', 'true')) {
            return true;
        }

        if (! $this->isResellerManagedHostingService($service)) {
            return false;
        }

        return $this->isResellerManagedHostingInvoice($invoice)
            && $this->resellerHostingAutoProvisionEnabled();
    }

    private function isResellerManagedHostingInvoice(Invoice $invoice): bool
    {
        $invoice->loadMissing('user', 'items.product', 'items.service.product');

        if (! $invoice->user?->reseller_id) {
            return false;
        }

        return $invoice->items->contains(
            fn (InvoiceItem $item) => $this->isHostingType($item->product?->type ?? $item->service?->product?->type)
        );
    }

    private function isResellerManagedHostingService(Service $service): bool
    {
        $service->loadMissing('product', 'user');

        if (! $this->isHostingType($service->product?->type)) {
            return false;
        }

        return $service->reseller_id !== null || $service->user?->reseller_id !== null;
    }

    private function isHostingType(?string $type): bool
    {
        return in_array($type, self::RESELLER_HOSTING_TYPES, true);
    }

    private function isManualProvisioningMode(): bool
    {
        return setting('provisioning_mode', 'automatic') === 'manual';
    }

    private function resellerHostingAutoProvisionEnabled(): bool
    {
        return $this->isTruthySetting('reseller_auto_provision_hosting', 'true');
    }

    private function isTruthySetting(string $key, string $default): bool
    {
        $value = Setting::getValue($key, $default);

        return in_array($value, ['true', '1', 1, true], true);
    }
}
