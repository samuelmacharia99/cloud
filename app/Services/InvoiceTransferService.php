<?php

namespace App\Services;

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
