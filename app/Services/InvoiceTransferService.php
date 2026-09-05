<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceTransferService
{
    /**
     * @return list<Invoice>
     */
    public function invoicesForServiceTransfer(Service $service, User $fromCustomer): array
    {
        return $this->collectTransferableInvoicesForService($service, $fromCustomer)->values()->all();
    }

    /**
     * @return list<string>
     */
    public function transferInvoicesForService(Service $service, User $fromCustomer, User $targetCustomer): array
    {
        $transferred = [];

        foreach ($this->collectTransferableInvoicesForService($service, $fromCustomer) as $invoice) {
            $this->transferInvoiceRecord($invoice, $fromCustomer, $targetCustomer, transferLineItemServices: false);
            $transferred[] = $this->invoiceLabel($invoice);
        }

        return $transferred;
    }

    /**
     * @return list<Invoice>
     */
    public function invoicesForDomainTransfer(Domain $domain, User $fromCustomer): array
    {
        return $this->collectTransferableInvoicesForDomain($domain, $fromCustomer)->values()->all();
    }

    /**
     * @return list<string>
     */
    public function transferInvoicesForDomain(Domain $domain, User $fromCustomer, User $targetCustomer): array
    {
        $transferred = [];

        foreach ($this->collectTransferableInvoicesForDomain($domain, $fromCustomer) as $invoice) {
            $this->transferInvoiceRecord($invoice, $fromCustomer, $targetCustomer, transferLineItemServices: false);
            $transferred[] = $this->invoiceLabel($invoice);
        }

        return $transferred;
    }

    /**
     * Invoices whose lines are only this domain and/or the listed services.
     *
     * @param  list<int>  $serviceIds
     * @return list<string>
     */
    public function transferInvoicesForDomainAndServices(
        Domain $domain,
        User $fromCustomer,
        User $targetCustomer,
        array $serviceIds,
    ): array {
        $transferred = [];

        foreach ($this->collectTransferableInvoicesForDomainAndServices($domain, $fromCustomer, $serviceIds) as $invoice) {
            $this->transferInvoiceRecord($invoice, $fromCustomer, $targetCustomer, transferLineItemServices: false);
            $transferred[] = $this->invoiceLabel($invoice);
        }

        return $transferred;
    }

    /**
     * @return array{
     *     from_customer: string,
     *     to_customer: string,
     *     services_transferred: int
     * }
     */
    public function transferToCustomer(Invoice $invoice, User $targetCustomer): array
    {
        $fromCustomer = $invoice->user;
        if (! $fromCustomer) {
            throw new \InvalidArgumentException('Invoice has no current owner.');
        }

        $this->assertInvoiceTransferAllowed($invoice, $targetCustomer);

        $servicesTransferred = 0;

        DB::transaction(function () use ($invoice, $fromCustomer, $targetCustomer, &$servicesTransferred) {
            $servicesTransferred = $this->transferInvoiceRecord(
                $invoice,
                $fromCustomer,
                $targetCustomer,
                transferLineItemServices: true,
            );
        });

        AdminActivityService::log(
            'invoice.transfer',
            "Transferred invoice {$this->invoiceLabel($invoice)} from {$fromCustomer->name} to {$targetCustomer->name}",
            $invoice->fresh(),
            [
                'from_user_id' => $fromCustomer->id,
                'to_user_id' => $targetCustomer->id,
                'services_transferred' => $servicesTransferred,
            ],
        );

        return [
            'from_customer' => $fromCustomer->name,
            'to_customer' => $targetCustomer->name,
            'services_transferred' => $servicesTransferred,
        ];
    }

    private function assertInvoiceTransferAllowed(Invoice $invoice, User $targetCustomer): void
    {
        if ($targetCustomer->is_admin) {
            throw new \InvalidArgumentException('Invoices cannot be transferred to administrator accounts.');
        }

        if ($targetCustomer->is_reseller) {
            throw new \InvalidArgumentException('Invoices cannot be transferred to reseller accounts. Transfer to one of the reseller\'s customers instead.');
        }

        if ((int) $invoice->user_id === (int) $targetCustomer->id) {
            throw new \InvalidArgumentException('Invoice is already assigned to this customer.');
        }
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function collectTransferableInvoicesForService(Service $service, User $fromCustomer): Collection
    {
        $candidateIds = collect();

        if ($service->invoice_id) {
            $candidateIds->push((int) $service->invoice_id);
        }

        $candidateIds = $candidateIds
            ->merge(InvoiceItem::query()->where('service_id', $service->id)->pluck('invoice_id'))
            ->unique()
            ->filter()
            ->values();

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->with('items')
            ->whereIn('id', $candidateIds)
            ->where('user_id', $fromCustomer->id)
            ->get()
            ->filter(fn (Invoice $invoice) => $this->invoiceBelongsToService($invoice, $service));
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function collectTransferableInvoicesForDomain(Domain $domain, User $fromCustomer): Collection
    {
        $candidateIds = InvoiceItem::query()
            ->where('domain_id', $domain->id)
            ->pluck('invoice_id');

        $renewalInvoiceIds = DomainRenewalOrder::query()
            ->where('domain_id', $domain->id)
            ->whereIn('status', ['pending', 'invoiced', 'queued'])
            ->get(['invoice_id', 'customer_invoice_id'])
            ->flatMap(fn (DomainRenewalOrder $order) => [$order->invoice_id, $order->customer_invoice_id]);

        $candidateIds = $candidateIds
            ->merge($renewalInvoiceIds)
            ->unique()
            ->filter()
            ->values();

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->with('items')
            ->whereIn('id', $candidateIds)
            ->where('user_id', $fromCustomer->id)
            ->get()
            ->filter(fn (Invoice $invoice) => $this->invoiceBelongsToDomain($invoice, $domain));
    }

    /**
     * @param  list<int>  $serviceIds
     * @return Collection<int, Invoice>
     */
    private function collectTransferableInvoicesForDomainAndServices(
        Domain $domain,
        User $fromCustomer,
        array $serviceIds,
    ): Collection {
        $serviceIds = array_values(array_unique(array_map('intval', $serviceIds)));

        $candidateIds = InvoiceItem::query()
            ->where(function ($query) use ($domain, $serviceIds): void {
                $query->where('domain_id', $domain->id);
                if ($serviceIds !== []) {
                    $query->orWhereIn('service_id', $serviceIds);
                }
            })
            ->pluck('invoice_id');

        $renewalInvoiceIds = DomainRenewalOrder::query()
            ->where('domain_id', $domain->id)
            ->whereIn('status', ['pending', 'invoiced', 'queued'])
            ->get(['invoice_id', 'customer_invoice_id'])
            ->flatMap(fn (DomainRenewalOrder $order) => [$order->invoice_id, $order->customer_invoice_id]);

        $candidateIds = $candidateIds
            ->merge($renewalInvoiceIds)
            ->unique()
            ->filter()
            ->values();

        if ($candidateIds->isEmpty()) {
            return collect();
        }

        return Invoice::query()
            ->with('items')
            ->whereIn('id', $candidateIds)
            ->where('user_id', $fromCustomer->id)
            ->get()
            ->filter(fn (Invoice $invoice) => $this->invoiceBelongsToDomainOrServices($invoice, $domain, $serviceIds));
    }

    /**
     * @param  list<int>  $serviceIds
     */
    private function invoiceBelongsToDomainOrServices(Invoice $invoice, Domain $domain, array $serviceIds): bool
    {
        $items = $invoice->items;

        if ($items->isEmpty()) {
            return $this->invoiceBelongsToDomain($invoice, $domain);
        }

        return $items->every(function (InvoiceItem $item) use ($domain, $serviceIds): bool {
            if (filled($item->service_id)) {
                return in_array((int) $item->service_id, $serviceIds, true);
            }

            if (filled($item->domain_id)) {
                return (int) $item->domain_id === (int) $domain->id;
            }

            return false;
        });
    }

    private function invoiceBelongsToDomain(Invoice $invoice, Domain $domain): bool
    {
        $items = $invoice->items;

        if ($items->isEmpty()) {
            return DomainRenewalOrder::query()
                ->where('domain_id', $domain->id)
                ->where(function ($query) use ($invoice): void {
                    $query->where('invoice_id', $invoice->id)
                        ->orWhere('customer_invoice_id', $invoice->id);
                })
                ->exists();
        }

        if ($items->contains(fn (InvoiceItem $item) => filled($item->service_id))) {
            return false;
        }

        $domainItems = $items->whereNotNull('domain_id');

        if ($domainItems->isEmpty()) {
            return false;
        }

        return $domainItems->every(
            fn (InvoiceItem $item) => (int) $item->domain_id === (int) $domain->id
        );
    }

    private function invoiceBelongsToService(Invoice $invoice, Service $service): bool
    {
        $serviceItems = $invoice->items->whereNotNull('service_id');

        if ($serviceItems->isEmpty()) {
            return (int) $service->invoice_id === (int) $invoice->id;
        }

        return $serviceItems->every(
            fn (InvoiceItem $item) => (int) $item->service_id === (int) $service->id
        );
    }

    private function transferInvoiceRecord(
        Invoice $invoice,
        User $fromCustomer,
        User $targetCustomer,
        bool $transferLineItemServices,
    ): int {
        $servicesTransferred = 0;

        if ($transferLineItemServices) {
            $serviceIds = $invoice->items()
                ->whereNotNull('service_id')
                ->pluck('service_id')
                ->unique()
                ->values();

            if ($serviceIds->isNotEmpty()) {
                $servicesTransferred = Service::query()
                    ->whereIn('id', $serviceIds)
                    ->where('user_id', $fromCustomer->id)
                    ->update([
                        'user_id' => $targetCustomer->id,
                        'reseller_id' => $targetCustomer->reseller_id,
                    ]);
            }
        }

        $invoice->update([
            'user_id' => $targetCustomer->id,
            'notes' => $this->appendTransferNote(
                $invoice,
                $fromCustomer,
                $targetCustomer,
                $transferLineItemServices,
            ),
        ]);

        Payment::query()
            ->where('invoice_id', $invoice->id)
            ->update(['user_id' => $targetCustomer->id]);

        Order::query()
            ->where('invoice_id', $invoice->id)
            ->update(['user_id' => $targetCustomer->id]);

        ResellerDomainOrder::query()
            ->where('customer_invoice_id', $invoice->id)
            ->update(['customer_id' => $targetCustomer->id]);

        return $servicesTransferred;
    }

    private function appendTransferNote(
        Invoice $invoice,
        User $fromCustomer,
        User $targetCustomer,
        bool $includedServices,
    ): string {
        $note = sprintf(
            '[Transfer %s] Moved from %s (#%d) to %s (#%d)%s.',
            now()->format('Y-m-d H:i'),
            $fromCustomer->name,
            $fromCustomer->id,
            $targetCustomer->name,
            $targetCustomer->id,
            $includedServices ? ' with linked services' : '',
        );

        $existing = trim((string) ($invoice->notes ?? ''));

        return $existing !== '' ? $existing."\n".$note : $note;
    }

    private function invoiceLabel(Invoice $invoice): string
    {
        return filled($invoice->invoice_number)
            ? (string) $invoice->invoice_number
            : '#'.$invoice->id;
    }
}
