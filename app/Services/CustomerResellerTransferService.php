<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\NotificationEvent;
use App\Enums\TicketHandledBy;
use App\Mail\CustomerAccountTransferredMail;
use App\Mail\ResellerCustomerAssignedMail;
use App\Models\Domain;
use App\Models\DomainRenewalOrder;
use App\Models\Invoice;
use App\Models\ResellerDomainOrder;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerResellerTransferService
{
    public function __construct(
        private ResellerBrandingResolver $brandingResolver,
        private ResellerMailService $resellerMail,
        private EmailDeliveryService $emailDelivery,
        private ResellerServiceCatalogMatchService $catalogMatcher,
    ) {}

    /**
     * @return array{
     *     customer: array{id: int, name: string, email: string, current_owner: string},
     *     target: array{id: ?int, name: string, email: ?string},
     *     counts: array{services: int, domains: int, open_invoices: int, open_tickets: int, da_accounts: int},
     *     open_invoices: list<array{number: string, status: string, total: float, due_date: ?string}>,
     *     warnings: list<string>,
     *     blockers: list<string>,
     *     will_cancel_invoices: bool,
     *     will_send_customer_email: bool,
     *     service_mappings: list<array{service_id: int, service_name: string, from_product: ?string, to_listing: ?string, match_type: ?string}>
     * }
     */
    public function preview(User $customer, ?User $targetReseller): array
    {
        $this->assertTransferAllowed($customer, $targetReseller);

        $services = Service::query()->where('user_id', $customer->id)->count();
        $domains = Domain::query()->where('user_id', $customer->id)->count();
        $openInvoices = $this->openInvoicesQuery($customer)->get();
        $openTickets = Ticket::query()
            ->where('user_id', $customer->id)
            ->where('status', '!=', 'closed')
            ->count();

        $daAccounts = $this->sharedHostingDaServices($customer)->count();

        $warnings = [];
        $blockers = [];

        if ($targetReseller !== null) {
            if ($targetReseller->isResellerSuspended()) {
                $warnings[] = 'Target reseller account is currently suspended.';
            }

            if (! $targetReseller->hasResellerPackage()) {
                $warnings[] = 'Target reseller has no active package assigned.';
            } elseif ($targetReseller->isAtUserLimit()) {
                $warnings[] = 'Target reseller is at or over their customer user limit.';
            }

            if ($daAccounts > 0 && ! filled($targetReseller->directadmin_username)) {
                $warnings[] = "{$daAccounts} shared-hosting account(s) on DirectAdmin cannot be moved under the reseller until a DirectAdmin account is linked.";
            }

            if (! $this->emailDelivery->mailConfiguredFor()) {
                $warnings[] = 'Platform email is not configured — customer notification may fail.';
            }
        }

        $serviceMappings = $targetReseller
            ? $this->previewServiceCatalogMappings($customer, $targetReseller, $warnings)
            : [];

        return [
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'current_owner' => $customer->reseller?->name ?? 'Platform (direct)',
            ],
            'target' => [
                'id' => $targetReseller?->id,
                'name' => $targetReseller?->name ?? 'Platform (direct)',
                'email' => $targetReseller?->email,
            ],
            'counts' => [
                'services' => $services,
                'domains' => $domains,
                'open_invoices' => $openInvoices->count(),
                'open_tickets' => $openTickets,
                'da_accounts' => $daAccounts,
            ],
            'open_invoices' => $openInvoices->map(fn (Invoice $invoice) => [
                'number' => $invoice->invoice_number,
                'status' => $invoice->status instanceof InvoiceStatus ? $invoice->status->value : (string) $invoice->status,
                'total' => (float) $invoice->total,
                'due_date' => $invoice->due_date?->toDateString(),
            ])->values()->all(),
            'warnings' => $warnings,
            'blockers' => $blockers,
            'will_cancel_invoices' => $targetReseller !== null && $openInvoices->isNotEmpty(),
            'will_send_customer_email' => $targetReseller !== null,
            'service_mappings' => $serviceMappings,
        ];
    }

    /**
     * Reassign a managed customer (and their managed resources) to another reseller,
     * or back to platform management when $targetReseller is null.
     *
     * @return array{
     *     from_reseller: ?string,
     *     to_reseller: string,
     *     cancelled_invoices: int,
     *     da_warnings: list<string>,
     *     email_sent: bool,
     *     reseller_email_sent: bool,
     *     catalog_warnings: list<string>
     * }
     */
    public function transfer(User $customer, ?User $targetReseller): array
    {
        $this->assertTransferAllowed($customer, $targetReseller);

        $previousResellerName = $customer->reseller?->name;
        $previousResellerId = $customer->reseller_id;
        $newResellerId = $targetReseller?->id;

        $cancelledInvoices = 0;
        $catalogWarnings = [];

        DB::transaction(function () use ($customer, $newResellerId, $targetReseller, &$cancelledInvoices) {
            if ($targetReseller !== null) {
                $cancelledInvoices = $this->cancelOpenInvoices($customer);
            }

            $customer->update(['reseller_id' => $newResellerId]);

            Service::query()
                ->where('user_id', $customer->id)
                ->update(['reseller_id' => $newResellerId]);

            Domain::query()
                ->where('user_id', $customer->id)
                ->update(['reseller_id' => $newResellerId]);

            ResellerDomainOrder::query()
                ->where('customer_id', $customer->id)
                ->update(['reseller_id' => $newResellerId]);

            $this->syncOpenTickets($customer, $newResellerId);
        });

        if ($targetReseller !== null) {
            $catalogWarnings = $this->assignResellerCatalogToServices($customer, $targetReseller);
        } else {
            $this->clearResellerCatalogFromServices($customer);
        }

        $daWarnings = $this->syncDirectAdminHostingAccounts($customer, $targetReseller);

        $toLabel = $targetReseller?->name ?? 'Platform (direct)';

        $emailSent = false;
        $resellerEmailSent = false;

        if ($targetReseller !== null) {
            $emailSent = $this->sendCustomerTransferEmail($customer->fresh());
            $resellerEmailSent = $this->sendResellerAssignmentEmail(
                $targetReseller,
                $customer->fresh(),
                [
                    'services' => Service::where('user_id', $customer->id)->count(),
                    'domains' => Domain::where('user_id', $customer->id)->count(),
                    'cancelled_invoices' => $cancelledInvoices,
                    'from_label' => $previousResellerName ?? 'Platform (direct)',
                ],
            );
        }

        AdminActivityService::log(
            'customer.reseller_transfer',
            "Transferred customer {$customer->name} from ".($previousResellerName ?? 'Platform')." to {$toLabel}",
            $customer,
            [
                'from_reseller_id' => $previousResellerId,
                'to_reseller_id' => $newResellerId,
                'cancelled_invoices' => $cancelledInvoices,
                'da_warnings' => $daWarnings,
                'catalog_warnings' => $catalogWarnings,
                'customer_email_sent' => $emailSent,
                'reseller_email_sent' => $resellerEmailSent,
            ],
        );

        return [
            'from_reseller' => $previousResellerName,
            'to_reseller' => $toLabel,
            'cancelled_invoices' => $cancelledInvoices,
            'da_warnings' => $daWarnings,
            'catalog_warnings' => $catalogWarnings,
            'email_sent' => $emailSent,
            'reseller_email_sent' => $resellerEmailSent,
        ];
    }

    private function assertTransferAllowed(User $customer, ?User $targetReseller): void
    {
        if ($customer->is_admin || $customer->is_reseller) {
            throw new \InvalidArgumentException('Only non-reseller customer accounts can be transferred.');
        }

        if ($targetReseller !== null) {
            if (! $targetReseller->is_reseller) {
                throw new \InvalidArgumentException('Target user is not a reseller.');
            }

            if ($customer->id === $targetReseller->id) {
                throw new \InvalidArgumentException('Cannot transfer a customer to themselves.');
            }

            if ($customer->reseller_id === $targetReseller->id) {
                throw new \InvalidArgumentException('Customer is already assigned to this reseller.');
            }
        } elseif ($customer->reseller_id === null) {
            throw new \InvalidArgumentException('Customer is already managed by the platform.');
        }
    }

    private function openInvoicesQuery(User $customer)
    {
        return Invoice::query()
            ->where('user_id', $customer->id)
            ->whereIn('status', [
                InvoiceStatus::Draft->value,
                InvoiceStatus::Unpaid->value,
                InvoiceStatus::Overdue->value,
            ]);
    }

    private function cancelOpenInvoices(User $customer): int
    {
        $invoices = $this->openInvoicesQuery($customer)->get();
        $stamp = '[Cancelled: account reassigned on '.now()->toDateString().']';

        foreach ($invoices as $invoice) {
            $notes = trim(($invoice->notes ?? '')."\n".$stamp);

            $invoice->update([
                'status' => InvoiceStatus::Cancelled,
                'notes' => $notes,
            ]);

            Service::query()
                ->where('invoice_id', $invoice->id)
                ->update(['invoice_id' => null]);

            DomainRenewalOrder::query()
                ->where('invoice_id', $invoice->id)
                ->whereIn('status', ['pending', 'invoiced'])
                ->update(['status' => 'expired']);
        }

        return $invoices->count();
    }

    private function syncOpenTickets(User $customer, ?int $newResellerId): void
    {
        Ticket::query()
            ->where('user_id', $customer->id)
            ->where('status', '!=', 'closed')
            ->update([
                'reseller_id' => $newResellerId,
                'handled_by' => $newResellerId
                    ? TicketHandledBy::Reseller->value
                    : TicketHandledBy::Platform->value,
                'escalated_at' => null,
                'escalated_by' => null,
                'escalation_note' => null,
            ]);
    }

    /**
     * @return Collection<int, Service>
     */
    private function sharedHostingDaServices(User $customer)
    {
        return Service::query()
            ->where('user_id', $customer->id)
            ->whereHas('product', fn ($q) => $q->where('type', 'shared_hosting'))
            ->with(['product', 'node'])
            ->get()
            ->filter(function (Service $service) {
                $meta = $service->service_meta ?? [];
                $username = $meta['username'] ?? null;

                return $username && $service->node && $service->node->type === 'directadmin';
            });
    }

    /**
     * Best-effort move of shared-hosting accounts on DirectAdmin when reseller ownership changes.
     *
     * @return list<string>
     */
    private function syncDirectAdminHostingAccounts(User $customer, ?User $targetReseller): array
    {
        $warnings = [];
        $newResellerDa = $targetReseller?->directadmin_username;

        foreach ($this->sharedHostingDaServices($customer) as $service) {
            $meta = $service->service_meta ?? [];
            $username = $meta['username'] ?? null;
            $node = $service->node;

            $directAdmin = new DirectAdminService($node);

            if (! $directAdmin->isConfigured()) {
                $warnings[] = "Service #{$service->id}: DirectAdmin API not configured on node {$node->name}.";

                continue;
            }

            $result = $directAdmin->reassignUserReseller(
                (string) $username,
                filled($newResellerDa) ? (string) $newResellerDa : null,
            );

            if ($result['success']) {
                $meta['directadmin_reseller'] = filled($newResellerDa) ? (string) $newResellerDa : null;
                $service->update(['service_meta' => $meta]);
            } else {
                $message = "Service #{$service->id} ({$username}): {$result['message']}";
                $warnings[] = $message;
                Log::warning('DirectAdmin reseller reassignment failed during customer transfer', [
                    'customer_id' => $customer->id,
                    'service_id' => $service->id,
                    'username' => $username,
                    'target_reseller_da' => $newResellerDa,
                    'error' => $result['message'],
                ]);
            }
        }

        return $warnings;
    }

    /**
     * @param  list<string>  $warnings
     * @return list<array{service_id: int, service_name: string, from_product: ?string, to_listing: ?string, match_type: ?string}>
     */
    private function previewServiceCatalogMappings(User $customer, User $targetReseller, array &$warnings): array
    {
        $mappings = [];

        foreach (Service::query()->where('user_id', $customer->id)->with('product')->get() as $service) {
            $match = $this->catalogMatcher->closestMatch($targetReseller, $service);

            if (! $match) {
                $warnings[] = "Service #{$service->id} ({$service->name}): no matching catalog plan on {$targetReseller->name}.";

                $mappings[] = [
                    'service_id' => $service->id,
                    'service_name' => $service->name,
                    'from_product' => $service->product?->name,
                    'to_listing' => null,
                    'match_type' => null,
                ];

                continue;
            }

            $mappings[] = [
                'service_id' => $service->id,
                'service_name' => $service->name,
                'from_product' => $service->product?->name,
                'to_listing' => $match['listing']->name,
                'match_type' => $match['match_type'],
            ];
        }

        return $mappings;
    }

    /**
     * @return list<string>
     */
    private function assignResellerCatalogToServices(User $customer, User $targetReseller): array
    {
        $warnings = [];

        foreach (Service::query()->where('user_id', $customer->id)->with('product')->get() as $service) {
            $match = $this->catalogMatcher->applyMatch($targetReseller, $service);

            if (! $match) {
                $warnings[] = "Service #{$service->id} ({$service->name}): could not map to a reseller catalog plan.";
            }
        }

        return $warnings;
    }

    private function clearResellerCatalogFromServices(User $customer): void
    {
        foreach (Service::query()->where('user_id', $customer->id)->get() as $service) {
            $this->catalogMatcher->clearResellerCatalogAssignment($service);
        }
    }

    private function sendCustomerTransferEmail(User $customer): bool
    {
        if (! $this->emailDelivery->mailConfiguredFor()) {
            Log::warning('Customer transfer email skipped: mail not configured', ['customer_id' => $customer->id]);

            return false;
        }

        $branding = $this->brandingResolver->forCustomer($customer);
        $portalUrl = $branding['portal_url'] ?: route('login');
        $mailable = new CustomerAccountTransferredMail($customer, $portalUrl);
        $subject = 'Your '.$branding['company_name'].' account has been updated';

        try {
            $this->resellerMail->sendBrandedWithPlatformFallback($customer, $mailable);
            $this->emailDelivery->logEmail(
                $customer->email,
                $subject,
                'sent',
                null,
                'Account transfer notification',
                NotificationEvent::CustomerAccountTransferred,
                $customer->id,
            );

            return true;
        } catch (\Throwable $e) {
            Log::error('Customer transfer email failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            $this->emailDelivery->logEmail(
                $customer->email,
                $subject,
                'failed',
                $e->getMessage(),
                'Account transfer notification',
                NotificationEvent::CustomerAccountTransferred,
                $customer->id,
            );

            return false;
        }
    }

    /**
     * @param  array{services: int, domains: int, cancelled_invoices: int, from_label: string}  $summary
     */
    private function sendResellerAssignmentEmail(User $reseller, User $customer, array $summary): bool
    {
        $mailable = new ResellerCustomerAssignedMail($reseller, $customer, $summary);
        $subject = 'New customer assigned: '.$customer->name;

        try {
            return $this->emailDelivery->sendPlatformMailable(
                $reseller->email,
                $mailable,
                $subject,
                NotificationEvent::ResellerCustomerAssigned,
                $reseller,
            );
        } catch (\Throwable $e) {
            Log::error('Reseller assignment email failed', [
                'reseller_id' => $reseller->id,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
