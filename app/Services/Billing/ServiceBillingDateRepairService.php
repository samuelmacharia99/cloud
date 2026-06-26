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
                    'duplicate_invoices' => $this->openErroneousRenewalInvoices($service, $anchor),
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
            foreach ($this->openErroneousRenewalInvoices($service, $anchorInvoice) as $duplicate) {
                if ($cancelDuplicates) {
                    $cancelledIds[] = $duplicate->id;
                }
            }

            return ['updated' => false, 'cancelled_invoice_ids' => $cancelledIds];
        }

        $service->update(['next_due_date' => $expected]);

        if ($cancelDuplicates) {
            foreach ($this->openErroneousRenewalInvoices($service, $anchorInvoice) as $duplicate) {
                $duplicate->update([
                    'status' => InvoiceStatus::Cancelled,
                    'notes' => trim(($duplicate->notes ?? '')."\nCancelled: duplicate renewal after paid invoice #{$anchorInvoice->invoice_number}."),
                ]);
                $cancelledIds[] = $duplicate->id;
            }
        }

        $this->relinkServiceToPaidInvoice($service->fresh(), $anchorInvoice, $cancelledIds);

        return ['updated' => true, 'cancelled_invoice_ids' => $cancelledIds];
    }

    public function relinkServiceToPaidInvoice(Service $service, Invoice $anchorInvoice, array $cancelledInvoiceIds = []): void
    {
        $current = $service->invoice_id ? Invoice::find($service->invoice_id) : null;

        $shouldRelink = $current === null
            || in_array($current->id, $cancelledInvoiceIds, true)
            || in_array($current->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue, InvoiceStatus::Draft], true);

        if ($shouldRelink) {
            $service->update(['invoice_id' => $anchorInvoice->id]);
        }
    }

    public function expectedNextDueDate(Service $service, Invoice $anchorInvoice): Carbon
    {
        $reference = $anchorInvoice->paid_date
            ?? $anchorInvoice->due_date
            ?? now();

        return $service->calculateNextDueDateAfterRenewal(Carbon::parse($reference)->startOfDay());
    }

    /**
     * Open auto-renewal invoices that should not remain after a paid renewal for this period.
     *
     * @return Collection<int, Invoice>
     */
    public function openErroneousRenewalInvoices(Service $service, Invoice $paidAnchor): Collection
    {
        if (! $paidAnchor->due_date) {
            return collect();
        }

        $periodDue = $paidAnchor->due_date->toDateString();

        $fromLineItems = $this->duplicateOpenRenewalInvoices($service, $paidAnchor);

        $fromServiceLink = collect();

        if ($service->invoice_id && $service->invoice_id !== $paidAnchor->id) {
            $linked = Invoice::query()
                ->whereKey($service->invoice_id)
                ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Overdue, InvoiceStatus::Draft])
                ->where(function ($query) {
                    $query->where('notes', 'like', '%Auto-generated renewal invoice%')
                        ->orWhere('notes', 'like', '%Manual renewal%');
                })
                ->whereDate('due_date', '<=', $periodDue)
                ->first();

            if ($linked) {
                $fromServiceLink->push($linked);
            }
        }

        return $fromLineItems
            ->merge($fromServiceLink)
            ->unique('id')
            ->values();
    }

    /**
     * Open auto-renewal invoices for the same billing period as a paid renewal (line items).
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

    /**
     * Services whose invoice_id still points at an open auto-renewal despite a paid renewal existing.
     *
     * @return Collection<int, array{service: Service, anchor_invoice: Invoice, open_invoice: Invoice}>
     */
    public function findMislinkedRenewalServices(): Collection
    {
        $results = [];

        Service::query()
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->whereNotNull('invoice_id')
            ->with('invoice')
            ->chunkById(100, function ($services) use (&$results) {
                foreach ($services as $service) {
                    $open = $service->invoice;

                    if (! $open || ! in_array($open->status, [InvoiceStatus::Unpaid, InvoiceStatus::Overdue, InvoiceStatus::Draft], true)) {
                        continue;
                    }

                    if (! $this->isRenewalInvoiceNotes($open->notes)) {
                        continue;
                    }

                    $anchor = $this->latestPaidRenewalInvoiceForService($service);

                    if (! $anchor || $anchor->id === $open->id) {
                        continue;
                    }

                    $results[] = [
                        'service' => $service,
                        'anchor_invoice' => $anchor,
                        'open_invoice' => $open,
                    ];
                }
            });

        return collect($results);
    }

    /**
     * @return array{cancelled_invoice_ids: list<int>}
     */
    public function repairMislinkedService(Service $service, Invoice $anchorInvoice, Invoice $openInvoice): array
    {
        $openInvoice->update([
            'status' => InvoiceStatus::Cancelled,
            'notes' => trim(($openInvoice->notes ?? '')."\nCancelled: duplicate renewal after paid invoice #{$anchorInvoice->invoice_number}."),
        ]);

        $this->relinkServiceToPaidInvoice($service->fresh(), $anchorInvoice, [$openInvoice->id]);

        return ['cancelled_invoice_ids' => [$openInvoice->id]];
    }

    private function latestPaidRenewalInvoiceForService(Service $service): ?Invoice
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
            ->where(function ($query) use ($service) {
                $query->whereHas('items', fn ($q) => $q->where('service_id', $service->id))
                    ->orWhere('id', $service->invoice_id);
            })
            ->orderByDesc('due_date')
            ->first();
    }

    private function isRenewalInvoiceNotes(?string $notes): bool
    {
        if ($notes === null || $notes === '') {
            return false;
        }

        return str_contains($notes, 'Auto-generated renewal invoice')
            || str_contains($notes, 'Manual renewal');
    }
}
