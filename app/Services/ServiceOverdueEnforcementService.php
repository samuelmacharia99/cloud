<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds services that should be suspended, unsuspended, or terminated based on invoice state.
 *
 * Applies to direct customers and reseller-managed customers. Invoices are matched through
 * both service.invoice_id and invoice line items so renewal billing always triggers enforcement.
 */
class ServiceOverdueEnforcementService
{
    public function isSuspensionEnabled(): bool
    {
        return in_array(Setting::getValue('suspend_on_overdue', 'false'), ['1', 'true', true], true);
    }

    public function gracePeriodDays(): int
    {
        return max(0, (int) Setting::getValue('grace_period_days', 5));
    }

    public function graceCutoffDate(?Carbon $reference = null): Carbon
    {
        return ($reference ?? now())->copy()->startOfDay()->subDays($this->gracePeriodDays());
    }

    /**
     * Active services with overdue invoices past the configured grace period.
     *
     * @return Builder<Service>
     */
    public function activeServicesWithOverdueInvoicePastGraceQuery(?Carbon $reference = null): Builder
    {
        $graceCutoff = $this->graceCutoffDate($reference);

        return Service::query()
            ->with(['user', 'invoice', 'product'])
            ->where('status', ServiceStatus::Active)
            ->where(function (Builder $query) use ($graceCutoff) {
                $query->whereHas('invoice', fn (Builder $q) => $this->applyOverduePastGraceConstraint($q, $graceCutoff))
                    ->orWhereHas('invoiceItems.invoice', fn (Builder $q) => $this->applyOverduePastGraceConstraint($q, $graceCutoff));
            });
    }

    /**
     * Active services with unpaid/overdue invoices due on the reference date.
     *
     * @return Builder<Service>
     */
    public function activeServicesWithUnpaidInvoiceDueOnQuery(Carbon $date): Builder
    {
        $dueDate = $date->copy()->startOfDay()->toDateString();

        return Service::query()
            ->with(['user', 'invoice', 'product'])
            ->where('status', ServiceStatus::Active)
            ->where(function (Builder $query) use ($dueDate) {
                $query->whereHas('invoice', fn (Builder $q) => $this->applyUnpaidDueOnConstraint($q, $dueDate))
                    ->orWhereHas('invoiceItems.invoice', fn (Builder $q) => $this->applyUnpaidDueOnConstraint($q, $dueDate));
            });
    }

    /**
     * Suspended services that may be eligible for billing unsuspend (final check via canAutoUnsuspendForPaidInvoice).
     *
     * @return Builder<Service>
     */
    public function suspendedServicesWithPaidBillingInvoiceQuery(): Builder
    {
        return Service::query()
            ->with(['user', 'invoice', 'invoiceItems.invoice', 'product'])
            ->where('status', ServiceStatus::Suspended)
            ->where(function (Builder $query) {
                $query->whereNull('service_meta->'.ResellerEnforcementService::META_SUSPENSION_REASON)
                    ->orWhere('service_meta->'.ResellerEnforcementService::META_SUSPENSION_REASON, ResellerEnforcementService::REASON_INVOICE_OVERDUE);
            })
            ->where(function (Builder $query) {
                $query->whereHas('invoice', fn (Builder $q) => $this->applyPaidBillingInvoiceForCurrentPeriodConstraint($q))
                    ->orWhereHas('invoiceItems.invoice', fn (Builder $q) => $this->applyPaidBillingInvoiceForCurrentPeriodConstraint($q));
            })
            ->whereDoesntHave('invoice', fn (Builder $q) => $this->applyOpenBillingInvoiceConstraint($q))
            ->whereDoesntHave('invoiceItems.invoice', fn (Builder $q) => $this->applyOpenBillingInvoiceConstraint($q));
    }

    public function shouldSuspendForOverdueInvoice(Service $service): bool
    {
        if (! $this->isSuspensionEnabled()) {
            return false;
        }

        $graceCutoff = $this->graceCutoffDate();
        $today = now()->startOfDay();

        return $this->serviceHasUnpaidBillingInvoiceDue($service, $today)
            || $this->serviceMatchesOverduePastGrace($service, $graceCutoff)
            || $this->serviceHasUnpaidBillingPeriod($service, $today);
    }

    public function canAutoUnsuspendForPaidInvoice(Service $service): bool
    {
        if ($service->status !== ServiceStatus::Suspended) {
            return false;
        }

        $reason = $service->service_meta[ResellerEnforcementService::META_SUSPENSION_REASON] ?? null;

        if ($reason !== null && $reason !== ResellerEnforcementService::REASON_INVOICE_OVERDUE) {
            return false;
        }

        if ($this->shouldSuspendForOverdueInvoice($service)) {
            return false;
        }

        return $this->hasPaidInvoiceForCurrentBillingPeriod($service);
    }

    /**
     * Whether a paid renewal invoice exists for the service's current next_due_date period.
     */
    public function hasPaidInvoiceForCurrentBillingPeriod(Service $service): bool
    {
        if (! $service->next_due_date) {
            return true;
        }

        $periodDue = $service->next_due_date->copy()->startOfDay()->toDateString();

        return Invoice::query()
            ->where('user_id', $service->user_id)
            ->where('status', InvoiceStatus::Paid)
            ->where(function (Builder $query) {
                $query->whereNull('type')->orWhere('type', '!=', 'reseller_subscription');
            })
            ->whereDate('due_date', $periodDue)
            ->where(function (Builder $query) use ($service) {
                $query->whereHas('items', fn (Builder $q) => $q->where('service_id', $service->id));

                if ($service->invoice_id) {
                    $query->orWhere('id', $service->invoice_id);
                }
            })
            ->exists();
    }

    public function clearInvoiceSuspensionMeta(Service $service): void
    {
        $meta = $service->service_meta ?? [];
        if (($meta[ResellerEnforcementService::META_SUSPENSION_REASON] ?? null) !== ResellerEnforcementService::REASON_INVOICE_OVERDUE) {
            return;
        }

        unset($meta[ResellerEnforcementService::META_SUSPENSION_REASON]);
        $service->update(['service_meta' => $meta ?: null]);
    }

    /**
     * Active or suspended services with unpaid invoices older than the cutoff date.
     *
     * @return Builder<Service>
     */
    public function servicesWithUnpaidInvoiceOnOrBeforeQuery(Carbon $cutoffDate): Builder
    {
        $cutoff = $cutoffDate->copy()->startOfDay()->toDateString();

        return Service::query()
            ->with(['user', 'invoice', 'product'])
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->where(function (Builder $query) use ($cutoff) {
                $query->whereHas('invoice', fn (Builder $q) => $this->applyUnpaidOnOrBeforeConstraint($q, $cutoff))
                    ->orWhereHas('invoiceItems.invoice', fn (Builder $q) => $this->applyUnpaidOnOrBeforeConstraint($q, $cutoff));
            });
    }

    /**
     * @return Collection<int, Service>
     */
    public function suspendedServicesForPaidInvoice(Invoice $invoice): Collection
    {
        if ($invoice->type === 'reseller_subscription' || ! $invoice->isPaid()) {
            return collect();
        }

        $serviceIds = $invoice->items()
            ->whereNotNull('service_id')
            ->pluck('service_id');

        return Service::query()
            ->where('status', ServiceStatus::Suspended)
            ->where(function (Builder $query) use ($invoice, $serviceIds) {
                $query->where('invoice_id', $invoice->id);

                if ($serviceIds->isNotEmpty()) {
                    $query->orWhereIn('id', $serviceIds);
                }
            })
            ->get();
    }

    public function isResellerManagedService(Service $service): bool
    {
        $service->loadMissing('user');

        return $service->reseller_id !== null || $service->user?->reseller_id !== null;
    }

    /**
     * Active services billed on this invoice (direct service link or line items).
     *
     * @return Collection<int, Service>
     */
    public function activeServicesForInvoice(Invoice $invoice): Collection
    {
        if ($invoice->type === 'reseller_subscription') {
            return collect();
        }

        $serviceIds = $invoice->items()
            ->whereNotNull('service_id')
            ->pluck('service_id');

        return Service::query()
            ->with(['user', 'product', 'node'])
            ->where('status', ServiceStatus::Active)
            ->where(function (Builder $query) use ($invoice, $serviceIds) {
                $query->where('invoice_id', $invoice->id);

                if ($serviceIds->isNotEmpty()) {
                    $query->orWhereIn('id', $serviceIds);
                }
            })
            ->get();
    }

    public function isDirectAdminService(Service $service): bool
    {
        $service->loadMissing('product');

        $driver = $service->provisioning_driver_key ?: $service->product?->provisioning_driver_key;

        return $driver === 'directadmin';
    }

    private function applyBillingInvoiceScope(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->whereNull('type')->orWhere('type', '!=', 'reseller_subscription');
        });
    }

    private function applyOverduePastGraceConstraint(Builder $query, Carbon $graceCutoff): void
    {
        $this->applyBillingInvoiceScope($query);
        $query->where('status', 'overdue')
            ->whereDate('due_date', '<', $graceCutoff->toDateString());
    }

    private function applyUnpaidDueOnConstraint(Builder $query, string $dueDate): void
    {
        $this->applyBillingInvoiceScope($query);
        $query->whereIn('status', ['unpaid', 'overdue'])
            ->whereDate('due_date', '=', $dueDate);
    }

    private function applyPaidBillingInvoiceConstraint(Builder $query): void
    {
        $this->applyBillingInvoiceScope($query);
        $query->where('status', InvoiceStatus::Paid);
    }

    private function applyPaidBillingInvoiceForCurrentPeriodConstraint(Builder $query): void
    {
        $this->applyPaidBillingInvoiceConstraint($query);
        $query->whereColumn('invoices.due_date', 'services.next_due_date');
    }

    /**
     * Service is past its billing date with no paid invoice for that period (e.g. open invoice was cancelled).
     */
    private function serviceHasUnpaidBillingPeriod(Service $service, Carbon $reference): bool
    {
        if (! $service->next_due_date) {
            return false;
        }

        if ($service->next_due_date->copy()->startOfDay()->gt($reference)) {
            return false;
        }

        return ! $this->hasPaidInvoiceForCurrentBillingPeriod($service);
    }

    private function applyOpenBillingInvoiceConstraint(Builder $query): void
    {
        $this->applyBillingInvoiceScope($query);
        $query->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue]);
    }

    private function applyUnpaidOnOrBeforeConstraint(Builder $query, string $cutoffDate): void
    {
        $this->applyBillingInvoiceScope($query);
        $query->whereIn('status', ['unpaid', 'overdue'])
            ->whereDate('due_date', '<=', $cutoffDate);
    }

    private function serviceMatchesOverduePastGrace(Service $service, Carbon $graceCutoff): bool
    {
        foreach ($this->billingInvoicesForService($service) as $invoice) {
            if ($this->invoiceIsOpenForBilling($invoice)
                && $invoice->due_date?->lt($graceCutoff)) {
                return true;
            }
        }

        return false;
    }

    private function serviceHasUnpaidBillingInvoiceDue(Service $service, Carbon $reference): bool
    {
        foreach ($this->billingInvoicesForService($service) as $invoice) {
            if (! $invoice->due_date) {
                continue;
            }

            if ($this->invoiceIsOpenForBilling($invoice)
                && $invoice->due_date->copy()->startOfDay()->lte($reference)) {
                return true;
            }
        }

        return false;
    }

    private function invoiceIsOpenForBilling(Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue], true);
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function billingInvoicesForService(Service $service): Collection
    {
        $service->loadMissing(['invoice', 'invoiceItems.invoice']);

        return collect([$service->invoice])
            ->merge($service->invoiceItems->pluck('invoice'))
            ->filter()
            ->unique('id')
            ->filter(fn (Invoice $invoice) => $invoice->type !== 'reseller_subscription')
            ->values();
    }

    private function serviceMatchesUnpaidDueOn(Service $service, string $dueDate): bool
    {
        foreach ($this->billingInvoicesForService($service) as $invoice) {
            if ($this->invoiceIsOpenForBilling($invoice)
                && $invoice->due_date?->toDateString() === $dueDate) {
                return true;
            }
        }

        return false;
    }
}
