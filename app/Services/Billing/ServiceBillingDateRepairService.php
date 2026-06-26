<?php

namespace App\Services\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ServiceBillingDateRepairService
{
    public function __construct(
        private InvoiceSettlementService $settlement,
    ) {}

    /**
     * @return Collection<int, array{
     *     service: Service,
     *     anchor_invoice: Invoice,
     *     current_next_due_date: string,
     *     expected_next_due_date: string,
     *     duplicate_invoices: Collection<int, Invoice>
     * }>
     */
    public function findAffected(): Collection
    {
        $candidates = [];

        foreach ($this->paidRenewalInvoicesQuery()->cursor() as $invoice) {
            if (! $this->settlement->qualifiesForRenewalBillingAdvance($invoice)) {
                continue;
            }

            $invoice->loadMissing('items.service');

            foreach ($invoice->items->whereNotNull('service_id') as $item) {
                $service = $item->service;

                if (! $service || ! in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true)) {
                    continue;
                }

                $candidates[$service->id]['service'] = $service;
                $candidates[$service->id]['invoices'] ??= collect();
                $candidates[$service->id]['invoices']->push($invoice);
            }
        }

        return collect($candidates)
            ->map(function (array $row) {
                /** @var Service $service */
                $service = $row['service'];
                /** @var Collection<int, Invoice> $invoices */
                $invoices = $row['invoices']->unique('id')->sortByDesc(fn (Invoice $invoice) => $invoice->due_date?->timestamp ?? 0);

                /** @var Invoice $anchor */
                $anchor = $invoices->first();
                $expected = $this->expectedNextDueDate($service, $anchor);

                if (! $service->next_due_date || ! $service->next_due_date->startOfDay()->lt($expected)) {
                    return null;
                }

                return [
                    'service' => $service,
                    'anchor_invoice' => $anchor,
                    'current_next_due_date' => $service->next_due_date->toDateString(),
                    'expected_next_due_date' => $expected->toDateString(),
                    'duplicate_invoices' => $this->duplicateOpenRenewalInvoices($service, $anchor),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return array{updated: bool, cancelled_invoice_ids: list<int>}
     */
    public function repair(
        Service $service,
        Invoice $anchorInvoice,
        bool $cancelDuplicates = true,
        bool $dryRun = true,
    ): array {
        $expected = $this->expectedNextDueDate($service, $anchorInvoice);
        $cancelledIds = [];

        if ($dryRun) {
            foreach ($this->duplicateOpenRenewalInvoices($service, $anchorInvoice) as $duplicate) {
                if ($cancelDuplicates) {
                    $cancelledIds[] = $duplicate->id;
                }
            }

            return ['updated' => false, 'cancelled_invoice_ids' => $cancelledIds];
        }

        $service->update(['next_due_date' => $expected]);

        if ($cancelDuplicates) {
            foreach ($this->duplicateOpenRenewalInvoices($service, $anchorInvoice) as $duplicate) {
                $duplicate->update([
                    'status' => InvoiceStatus::Cancelled,
                    'notes' => trim(($duplicate->notes ?? '')."\nCancelled: duplicate renewal after paid invoice #{$anchorInvoice->invoice_number}."),
                ]);
                $cancelledIds[] = $duplicate->id;
            }
        }

        if ($service->invoice_id && in_array($service->invoice_id, $cancelledIds, true)) {
            $service->update(['invoice_id' => $anchorInvoice->id]);
        }

        return ['updated' => true, 'cancelled_invoice_ids' => $cancelledIds];
    }

    public function expectedNextDueDate(Service $service, Invoice $anchorInvoice): Carbon
    {
        $reference = $anchorInvoice->paid_date
            ?? $anchorInvoice->due_date
            ?? now();

        return $service->calculateNextDueDateAfterRenewal(Carbon::parse($reference)->startOfDay());
    }

    /**
     * Open auto-renewal invoices for the same billing period as a paid renewal.
     *
     * @return Collection<int, Invoice>
     */
    public function duplicateOpenRenewalInvoices(Service $service, Invoice $paidAnchor): Collection
    {
        if (! $paidAnchor->due_date) {
            return collect();
        }

        $periodDue = $paidAnchor->due_date->toDateString();

        return Invoice::query()
            ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue, InvoiceStatus::Draft])
            ->where(function ($query) {
                $query->where('notes', 'like', '%Auto-generated renewal invoice%')
                    ->orWhere('notes', 'like', '%Manual renewal%');
            })
            ->whereDate('due_date', $periodDue)
            ->whereHas('items', fn ($query) => $query->where('service_id', $service->id))
            ->orderByDesc('id')
            ->get();
    }

    private function paidRenewalInvoicesQuery()
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->where(function ($query) {
                $query->whereNull('type')
                    ->orWhere('type', '!=', 'reseller_subscription');
            })
            ->where(function ($query) {
                $query->where('notes', 'like', '%Auto-generated renewal invoice%')
                    ->orWhere('notes', 'like', '%Manual renewal%');
            })
            ->whereHas('items', fn ($query) => $query->whereNotNull('service_id'))
            ->with(['items.service', 'order'])
            ->orderByDesc('due_date');
    }
}
