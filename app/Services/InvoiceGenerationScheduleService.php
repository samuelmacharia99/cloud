<?php

namespace App\Services;

use App\Enums\ServiceStatus;
use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Central rules for when renewal invoices should be generated ahead of due dates.
 *
 * Applies to direct customers and reseller-managed customers alike:
 * - Monthly services: invoice N days before next_due_date (default 10).
 * - Non-monthly services (quarterly, semi-annual, annual): invoice N days before (default 30).
 * - Domains: invoice N days before expires_at (default 30, setting domain_renewal_advance_days).
 */
class InvoiceGenerationScheduleService
{
    public function monthlyServiceAdvanceDays(): int
    {
        return max(1, (int) Setting::getValue('service_monthly_invoice_advance_days', 10));
    }

    public function nonMonthlyServiceAdvanceDays(): int
    {
        return max(1, (int) Setting::getValue('service_renewal_invoice_advance_days', 30));
    }

    public function domainAdvanceDays(): int
    {
        return max(1, (int) Setting::getValue('domain_renewal_advance_days', 30));
    }

    public function serviceAdvanceDays(Service $service): int
    {
        return $this->advanceDaysForBillingCycle($service->billing_cycle ?? 'monthly');
    }

    public function advanceDaysForBillingCycle(string $billingCycle): int
    {
        return $billingCycle === 'monthly'
            ? $this->monthlyServiceAdvanceDays()
            : $this->nonMonthlyServiceAdvanceDays();
    }

    /**
     * Date the customer/reseller should pay a service renewal invoice (service next_due_date).
     */
    public function serviceInvoiceDueDate(Service $service): Carbon
    {
        return Carbon::parse($service->next_due_date)->startOfDay();
    }

    /**
     * Anchor date for domain renewal billing (domain expiry).
     */
    public function domainRenewalAnchorDate(Domain $domain): Carbon
    {
        return Carbon::parse($domain->expires_at)->startOfDay();
    }

    /**
     * Planned auto-invoice date shown on reseller domain records.
     */
    public function domainNextInvoiceDate(Domain $domain): Carbon
    {
        return $this->domainRenewalAnchorDate($domain)->copy()->subDays($this->domainAdvanceDays());
    }

    /**
     * Last day (inclusive) on which we should generate an invoice for this service's current period.
     */
    public function serviceInvoiceGenerateOnOrBefore(Service $service, ?Carbon $reference = null): Carbon
    {
        return $this->serviceInvoiceGenerateOnOrBeforeDate(
            Carbon::parse($service->next_due_date),
            $service->billing_cycle ?? 'monthly'
        );
    }

    public function serviceInvoiceGenerateOnOrBeforeDate(Carbon $nextDueDate, string $billingCycle): Carbon
    {
        return $nextDueDate->copy()->startOfDay()->subDays($this->advanceDaysForBillingCycle($billingCycle));
    }

    public function isResellerManagedService(Service $service): bool
    {
        $service->loadMissing('user');

        return $service->reseller_id !== null || $service->user?->reseller_id !== null;
    }

    public function isResellerManagedDomain(Domain $domain): bool
    {
        $domain->loadMissing('user');

        return $domain->reseller_id !== null || $domain->user?->reseller_id !== null;
    }

    public function isServiceDueForRenewalInvoice(Service $service, ?Carbon $reference = null): bool
    {
        if (! $service->next_due_date || $service->status !== ServiceStatus::Active) {
            return false;
        }

        $reference = ($reference ?? now())->copy()->startOfDay();
        $generateOnOrBefore = $this->serviceInvoiceGenerateOnOrBefore($service, $reference);

        return $reference->greaterThanOrEqualTo($generateOnOrBefore);
    }

    public function isDomainDueForRenewalInvoice(Domain $domain, ?Carbon $reference = null): bool
    {
        if ($domain->status !== 'active' || ! $domain->expires_at) {
            return false;
        }

        $reference = ($reference ?? now())->copy()->startOfDay();
        $expiresAt = Carbon::parse($domain->expires_at)->startOfDay();

        if ($expiresAt->lessThanOrEqualTo($reference)) {
            return false;
        }

        $generateOnOrBefore = $this->domainInvoiceGenerateOnOrBefore($domain, $reference);

        return $reference->greaterThanOrEqualTo($generateOnOrBefore);
    }

    public function domainInvoiceGenerateOnOrBefore(Domain $domain, ?Carbon $reference = null): Carbon
    {
        return $this->domainRenewalAnchorDate($domain)->copy()->subDays($this->domainAdvanceDays());
    }

    /**
     * Active services that are inside the advance window and have no open renewal invoice.
     */
    public function servicesDueForRenewalInvoiceQuery(?Carbon $reference = null): Builder
    {
        $today = ($reference ?? now())->copy()->startOfDay();
        $monthlyAdvance = $this->monthlyServiceAdvanceDays();
        $nonMonthlyAdvance = $this->nonMonthlyServiceAdvanceDays();

        $query = Service::query()
            ->with(['product.containerTemplate', 'user', 'containerDeployment.node'])
            ->where('status', 'active')
            ->whereNotNull('next_due_date')
            ->where(function (Builder $q) use ($today, $monthlyAdvance, $nonMonthlyAdvance) {
                $q->where(function (Builder $q) use ($today, $monthlyAdvance) {
                    $q->where('billing_cycle', 'monthly')
                        ->whereDate('next_due_date', '<=', $today->copy()->addDays($monthlyAdvance));
                })->orWhere(function (Builder $q) use ($today, $nonMonthlyAdvance) {
                    $q->whereNotIn('billing_cycle', ['monthly'])
                        ->whereDate('next_due_date', '<=', $today->copy()->addDays($nonMonthlyAdvance));
                });
            });

        return $this->applyWithoutOpenRenewalInvoiceConstraint($query);
    }

    /**
     * Active domains inside the advance window without a pending/invoiced renewal order.
     */
    public function domainsDueForRenewalInvoiceQuery(?Carbon $reference = null): Builder
    {
        $today = ($reference ?? now())->copy()->startOfDay();
        $advanceDays = $this->domainAdvanceDays();
        $lookbackDays = $advanceDays + 7;

        return Domain::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>', $today)
            ->whereDate('expires_at', '<=', $today->copy()->addDays($advanceDays))
            ->whereDoesntHave('renewalOrders', function (Builder $q) use ($lookbackDays) {
                $q->whereIn('status', ['pending', 'invoiced'])
                    ->where('created_at', '>=', now()->subDays($lookbackDays));
            })
            ->with(['user', 'domainExtension']);
    }

    /**
     * Exclude services that already have an unpaid/draft renewal invoice (via invoice_id or line items).
     */
    public function applyWithoutOpenRenewalInvoiceConstraint(Builder $query): Builder
    {
        $lookbackDays = max($this->monthlyServiceAdvanceDays(), $this->nonMonthlyServiceAdvanceDays()) + 14;
        $since = now()->subDays($lookbackDays);

        $serviceIdsWithOpenItems = InvoiceItem::query()
            ->whereNotNull('service_id')
            ->whereHas('invoice', function (Builder $q) use ($since) {
                $q->whereIn('status', ['draft', 'unpaid'])
                    ->where('created_at', '>=', $since);
            })
            ->pluck('service_id');

        return $query
            ->whereDoesntHave('invoice', function (Builder $q) use ($since) {
                $q->whereIn('status', ['draft', 'unpaid'])
                    ->where('created_at', '>=', $since);
            })
            ->when(
                $serviceIdsWithOpenItems->isNotEmpty(),
                fn (Builder $q) => $q->whereNotIn('id', $serviceIdsWithOpenItems)
            );
    }
}
