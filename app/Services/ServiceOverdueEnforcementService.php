<?php

namespace App\Services;

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
     * Suspended services whose linked billing invoice has been paid.
     *
     * @return Builder<Service>
     */
    public function suspendedServicesWithPaidBillingInvoiceQuery(): Builder
    {
        return Service::query()
            ->with(['user', 'invoice', 'product'])
            ->where('status', ServiceStatus::Suspended)
            ->where(function (Builder $query) {
                $query->whereNull('service_meta->'.ResellerEnforcementService::META_SUSPENSION_REASON)
                    ->orWhere('service_meta->'.ResellerEnforcementService::META_SUSPENSION_REASON, ResellerEnforcementService::REASON_INVOICE_OVERDUE);
            })
            ->where(function (Builder $query) {
                $query->whereHas('invoice', fn (Builder $q) => $this->applyPaidBillingInvoiceConstraint($q))
                    ->orWhereHas('invoiceItems.invoice', fn (Builder $q) => $this->applyPaidBillingInvoiceConstraint($q));
            });
    }

    public function shouldSuspendForOverdueInvoice(Service $service): bool
    {
        if (! $this->isSuspensionEnabled()) {
            return false;
        }

        $graceCutoff = $this->graceCutoffDate();
        $today = now()->startOfDay()->toDateString();

        return $this->serviceMatchesOverduePastGrace($service, $graceCutoff)
            || $this->serviceMatchesUnpaidDueOn($service, $today);
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

        return ! $this->shouldSuspendForOverdueInvoice($service);
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
        $query->where('status', 'paid');
    }

    private function applyUnpaidOnOrBeforeConstraint(Builder $query, string $cutoffDate): void
    {
        $this->applyBillingInvoiceScope($query);
        $query->whereIn('status', ['unpaid', 'overdue'])
            ->whereDate('due_date', '<=', $cutoffDate);
    }

    private function serviceMatchesOverduePastGrace(Service $service, Carbon $graceCutoff): bool
    {
        $service->loadMissing(['invoice', 'invoiceItems.invoice']);

        $invoices = collect([$service->invoice])
            ->merge($service->invoiceItems->pluck('invoice'))
            ->filter();

        foreach ($invoices as $invoice) {
            if ($invoice->type === 'reseller_subscription') {
                continue;
            }

            if ($invoice->status === 'overdue' && $invoice->due_date?->lt($graceCutoff)) {
                return true;
            }
        }

        return false;
    }

    private function serviceMatchesUnpaidDueOn(Service $service, string $dueDate): bool
    {
        $service->loadMissing(['invoice', 'invoiceItems.invoice']);

        $invoices = collect([$service->invoice])
            ->merge($service->invoiceItems->pluck('invoice'))
            ->filter();

        foreach ($invoices as $invoice) {
            if ($invoice->type === 'reseller_subscription') {
                continue;
            }

            if (in_array($invoice->status, ['unpaid', 'overdue'], true)
                && $invoice->due_date?->toDateString() === $dueDate) {
                return true;
            }
        }

        return false;
    }
}
