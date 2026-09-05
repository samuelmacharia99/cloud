<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DomainOwnershipTransferService
{
    public function __construct(
        private InvoiceTransferService $invoiceTransfer,
        private ServiceTransferService $serviceTransfer,
    ) {}

    /**
     * Hosting services on the current owner that are attached to this domain.
     *
     * @return Collection<int, Service>
     */
    public function linkedServicesForOwner(Domain $domain)
    {
        $ownerId = $domain->user_id;
        if (! $ownerId) {
            return collect();
        }

        $fqdn = strtolower($domain->fqdn());

        return Service::query()
            ->with(['product', 'user'])
            ->where('user_id', $ownerId)
            ->orderBy('id')
            ->get()
            ->filter(function (Service $service) use ($domain, $fqdn): bool {
                $meta = is_array($service->service_meta) ? $service->service_meta : [];

                if (! empty($meta['domain_id']) && (int) $meta['domain_id'] === (int) $domain->id) {
                    return true;
                }

                $attached = $service->attachedDomainName();

                return is_string($attached) && strtolower($attached) === $fqdn;
            })
            ->values();
    }

    /**
     * @return array{
     *     domain: array{id: int, fqdn: string},
     *     from: array{id: int, name: string, email: string, reseller: ?string},
     *     to: array{id: int, name: string, email: string, reseller: ?string},
     *     linked_services: list<array{id: int, name: string}>,
     *     warnings: list<string>
     * }
     */
    public function preview(Domain $domain, User $targetCustomer): array
    {
        $fromCustomer = $this->assertTransferAllowed($domain, $targetCustomer);
        $linkedServices = $this->linkedServicesForOwner($domain);

        return [
            'domain' => [
                'id' => $domain->id,
                'fqdn' => $domain->fqdn(),
            ],
            'from' => $this->customerSummary($fromCustomer),
            'to' => $this->customerSummary($targetCustomer),
            'linked_services' => $linkedServices->map(fn (Service $service) => [
                'id' => $service->id,
                'name' => $service->name,
            ])->values()->all(),
            'warnings' => $this->buildWarnings($domain, $fromCustomer, $targetCustomer, $linkedServices),
        ];
    }

    /**
     * @return array{
     *     from_customer: string,
     *     to_customer: string,
     *     invoices_transferred: list<string>,
     *     renewal_orders_moved: int,
     *     services_transferred: int,
     *     services_left: int
     * }
     */
    public function transfer(
        Domain $domain,
        User $targetCustomer,
        string $reason,
        User $admin,
        bool $transferServices = false,
    ): array {
        $fromCustomer = $this->assertTransferAllowed($domain, $targetCustomer);
        $linkedServices = $this->linkedServicesForOwner($domain);
        $previousResellerId = $domain->reseller_id;
        $invoicesTransferred = [];
        $renewalOrdersMoved = 0;
        $servicesTransferred = 0;

        DB::transaction(function () use (
            $domain,
            $targetCustomer,
            $fromCustomer,
            $reason,
            $admin,
            $transferServices,
            $linkedServices,
            &$invoicesTransferred,
            &$renewalOrdersMoved,
            &$servicesTransferred,
        ) {
            if ($transferServices) {
                foreach ($linkedServices as $service) {
                    $this->serviceTransfer->transfer($service, $targetCustomer, transferDomain: false);
                    $servicesTransferred++;
                }
            }

            $renewalOrdersMoved = $this->reassignOpenRenewalOrders($domain, $fromCustomer, $targetCustomer);

            $invoicesTransferred = $this->invoiceTransfer->transferInvoicesForDomain(
                $domain,
                $fromCustomer,
                $targetCustomer,
            );

            if ($transferServices && $linkedServices->isNotEmpty()) {
                $invoicesTransferred = array_values(array_unique(array_merge(
                    $invoicesTransferred,
                    $this->invoiceTransfer->transferInvoicesForDomainAndServices(
                        $domain,
                        $fromCustomer,
                        $targetCustomer,
                        $linkedServices->pluck('id')->all(),
                    ),
                )));
            }

            $notes = $domain->notes ?? [];
            if (! is_array($notes)) {
                $notes = filled($notes) ? [['type' => 'note', 'text' => (string) $notes]] : [];
            }

            $notes[] = [
                'type' => 'admin_ownership_transfer',
                'from' => $fromCustomer->name,
                'from_user_id' => $fromCustomer->id,
                'to' => $targetCustomer->name,
                'to_user_id' => $targetCustomer->id,
                'reason' => $reason,
                'transfer_services' => $transferServices,
                'service_ids' => $transferServices ? $linkedServices->pluck('id')->all() : [],
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'transferred_at' => now()->toIso8601String(),
            ];

            $domain->update([
                'user_id' => $targetCustomer->id,
                'reseller_id' => $targetCustomer->reseller_id,
                'pending_transfer_to_user_id' => null,
                'transfer_token' => null,
                'transfer_requested_at' => null,
                'notes' => $notes,
            ]);
        });

        AdminActivityService::log(
            'domain.transfer_ownership',
            "Transferred domain {$domain->fqdn()} from {$fromCustomer->name} to {$targetCustomer->name}",
            $domain->fresh(),
            [
                'from_user_id' => $fromCustomer->id,
                'to_user_id' => $targetCustomer->id,
                'from_reseller_id' => $previousResellerId,
                'to_reseller_id' => $targetCustomer->reseller_id,
                'reason' => $reason,
                'transfer_services' => $transferServices,
                'invoices_transferred' => $invoicesTransferred,
                'renewal_orders_moved' => $renewalOrdersMoved,
                'services_transferred' => $servicesTransferred,
            ],
        );

        return [
            'from_customer' => $fromCustomer->name,
            'to_customer' => $targetCustomer->name,
            'invoices_transferred' => $invoicesTransferred,
            'renewal_orders_moved' => $renewalOrdersMoved,
            'services_transferred' => $servicesTransferred,
            'services_left' => $transferServices ? 0 : $linkedServices->count(),
        ];
    }

    private function assertTransferAllowed(Domain $domain, User $targetCustomer): User
    {
        if ($targetCustomer->is_admin) {
            throw new \InvalidArgumentException('Domains cannot be transferred to administrator accounts.');
        }

        if ($targetCustomer->is_reseller) {
            throw new \InvalidArgumentException('Domains cannot be transferred to reseller accounts. Transfer to one of the reseller\'s customers instead.');
        }

        $fromCustomer = $domain->user;
        if (! $fromCustomer) {
            throw new \InvalidArgumentException('Domain has no current owner.');
        }

        if ((int) $domain->user_id === (int) $targetCustomer->id) {
            throw new \InvalidArgumentException('Domain is already assigned to this customer.');
        }

        return $fromCustomer;
    }

    /**
     * @param  Collection<int, Service>  $linkedServices
     * @return list<string>
     */
    private function buildWarnings(Domain $domain, User $fromCustomer, User $targetCustomer, $linkedServices): array
    {
        $warnings = [];

        if ((int) ($fromCustomer->reseller_id ?? 0) !== (int) ($targetCustomer->reseller_id ?? 0)) {
            $warnings[] = 'Customers belong to different resellers. Future renewals will bill through the new owner\'s reseller.';
        }

        $invoices = $this->invoiceTransfer->invoicesForDomainTransfer($domain, $fromCustomer);
        if ($invoices !== []) {
            $labels = array_map(
                fn ($invoice) => filled($invoice->invoice_number) ? $invoice->invoice_number : '#'.$invoice->id,
                $invoices,
            );
            $warnings[] = 'Open invoice(s) that only bill this domain will move: '.implode(', ', $labels).'.';
        }

        $openRenewals = DomainRenewalOrder::query()
            ->where('domain_id', $domain->id)
            ->whereIn('status', ['pending', 'invoiced', 'queued'])
            ->count();

        if ($openRenewals > 0) {
            $warnings[] = $openRenewals.' open renewal order(s) will move to the new owner.';
        }

        if ($linkedServices->isNotEmpty()) {
            $labels = $linkedServices->map(fn (Service $service) => $service->name.' (#'.$service->id.')')->implode(', ');
            $warnings[] = 'Choose whether to move the hosting service(s) with this domain: '.$labels.'.';
        }

        $warnings[] = 'Registry contact and nameservers are unchanged. This only moves the Talksasa account.';

        return $warnings;
    }

    /**
     * @return array{id: int, name: string, email: string, reseller: ?string}
     */
    private function customerSummary(User $customer): array
    {
        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
            'reseller' => $customer->reseller?->name,
        ];
    }

    private function reassignOpenRenewalOrders(Domain $domain, User $fromCustomer, User $targetCustomer): int
    {
        $orders = DomainRenewalOrder::query()
            ->where('domain_id', $domain->id)
            ->whereIn('status', ['pending', 'invoiced', 'queued'])
            ->where('user_id', $fromCustomer->id)
            ->get();

        foreach ($orders as $order) {
            $updates = [
                'user_id' => $targetCustomer->id,
                'reseller_id' => $targetCustomer->reseller_id,
            ];

            if ($order->customer_id && (int) $order->customer_id === (int) $fromCustomer->id) {
                $updates['customer_id'] = $targetCustomer->reseller_id ? $targetCustomer->id : null;
            } elseif (! $order->customer_id && $targetCustomer->reseller_id) {
                $updates['customer_id'] = $targetCustomer->id;
            }

            $order->update($updates);
        }

        return $orders->count();
    }
}
