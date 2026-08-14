<?php

namespace App\Services\Customer;

use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\Provisioning\MailDnsService;

/**
 * Actionable next steps for the customer dashboard and notification bell.
 */
class CustomerNextStepsService
{
    public function __construct(
        private MailcowProvisioningService $mailcow,
    ) {}

    /**
     * @return list<array{id: string, priority: int, title: string, body: string, url: string, tone: string}>
     */
    public function forUser(User $user): array
    {
        $steps = [];

        $unpaid = $user->invoices()
            ->whereIn('status', ['unpaid', 'overdue'])
            ->orderBy('due_date')
            ->take(3)
            ->get();

        foreach ($unpaid as $invoice) {
            $isOverdue = $invoice->status === InvoiceStatus::Overdue;
            $steps[] = [
                'id' => 'invoice-'.$invoice->id,
                'priority' => $isOverdue ? 100 : 90,
                'title' => $isOverdue ? 'Overdue invoice' : 'Pay invoice',
                'body' => $invoice->invoice_number.' · due '.optional($invoice->due_date)->format('M j, Y'),
                'url' => route('customer.invoices.show', $invoice),
                'tone' => $isOverdue ? 'danger' : 'warning',
            ];
        }

        $suspended = $user->services()->where('status', ServiceStatus::Suspended->value)->with('product')->take(3)->get();
        foreach ($suspended as $service) {
            $steps[] = [
                'id' => 'suspended-'.$service->id,
                'priority' => 95,
                'title' => 'Service suspended',
                'body' => ($service->product?->name ?? $service->name).' needs attention',
                'url' => route('customer.services.show', $service),
                'tone' => 'danger',
            ];
        }

        $expiring = $user->domains()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30))
            ->orderBy('expires_at')
            ->take(3)
            ->get();

        foreach ($expiring as $domain) {
            $steps[] = [
                'id' => 'domain-'.$domain->id,
                'priority' => 70,
                'title' => 'Domain expiring soon',
                'body' => $domain->fqdn().' · '.optional($domain->expires_at)->diffForHumans(),
                'url' => route('customer.domains.index'),
                'tone' => 'warning',
            ];
        }

        $openTickets = $user->tickets()->where('status', '!=', 'closed')->latest('updated_at')->take(2)->get();
        foreach ($openTickets as $ticket) {
            $steps[] = [
                'id' => 'ticket-'.$ticket->id,
                'priority' => 60,
                'title' => 'Open support ticket',
                'body' => '#'.$ticket->id.' · '.$ticket->subject,
                'url' => route('customer.tickets.show', $ticket),
                'tone' => 'info',
            ];
        }

        $emailServices = $user->services()
            ->with('product')
            ->where('status', ServiceStatus::Active->value)
            ->where(function ($q) {
                $q->where('provisioning_driver_key', 'mailcow')
                    ->orWhereHas('product', fn ($p) => $p->where('type', 'email_hosting'));
            })
            ->take(8)
            ->get();

        foreach ($emailServices as $service) {
            foreach ($this->emailSteps($service) as $step) {
                $steps[] = $step;
            }
        }

        if ($user->services()->where('status', ServiceStatus::Active->value)->doesntExist()
            && $user->domains()->doesntExist()) {
            $steps[] = [
                'id' => 'get-started',
                'priority' => 40,
                'title' => 'Get started',
                'body' => 'Deploy an app or order Email Hosting to begin.',
                'url' => $user->reseller_id
                    ? route('customer.catalog.index')
                    : route('customer.select-techstack'),
                'tone' => 'info',
            ];
        }

        usort($steps, fn ($a, $b) => $b['priority'] <=> $a['priority']);

        return array_slice($steps, 0, 8);
    }

    /**
     * @return list<array{id: string, priority: int, title: string, body: string, url: string, tone: string}>
     */
    private function emailSteps(Service $service): array
    {
        $steps = [];
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $domain = $meta['mailcow_domain'] ?? $meta['domain'] ?? $service->external_reference;

        try {
            $client = $this->mailcow->clientForService($service);
            $mailDomain = $this->mailcow->domainForService($service);
            $listed = $client->listMailboxes($mailDomain);
            $count = count($listed['data'] ?? []);

            if ($count === 0) {
                $steps[] = [
                    'id' => 'email-create-'.$service->id,
                    'priority' => 80,
                    'title' => 'Create your first mailbox',
                    'body' => ($domain ?: 'Email plan').' has no mailboxes yet',
                    'url' => route('customer.services.email.show', $service).'?tab=mailboxes',
                    'tone' => 'info',
                ];
            }
        } catch (\Throwable) {
            // Node/API may be unavailable; skip soft checks.
        }

        return $steps;
    }

    /**
     * Lightweight health snapshot for email inbox cards.
     *
     * @return array{
     *   domain: ?string,
     *   mailbox_count: int,
     *   mailbox_limit: int,
     *   msgs_per_day: int,
     *   dns_ok: ?bool,
     *   dns_note: string,
     *   mx_ok: ?bool,
     *   spf_ok: ?bool,
     *   dkim_ok: ?bool,
     *   dmarc_ok: ?bool,
     *   auth_checks: array{mx: string, spf: string, dkim: string, dmarc: string},
     *   ptr_note: string
     * }
     */
    public function emailHealth(Service $service): array
    {
        $limits = $this->mailcow->limitsForProduct($service->product);
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $domain = null;
        $mailboxCount = 0;
        $dnsOk = null;
        $dnsNote = 'DNS not checked';
        $mxOk = $spfOk = $dkimOk = $dmarcOk = null;
        $authChecks = [
            'mx' => 'Not checked',
            'spf' => 'Not checked',
            'dkim' => 'Not checked',
            'dmarc' => 'Not checked',
        ];
        $ptrNote = 'PTR (reverse DNS) for the mail server IP must be set by the host operator.';

        try {
            $domain = $this->mailcow->domainForService($service);
            $client = $this->mailcow->clientForService($service);
            $listed = $client->listMailboxes($domain);
            if ($listed['success']) {
                $mailboxCount = count($listed['data'] ?? []);
            }

            $delivery = app(MailDnsService::class)->deliverabilityHealth($service);
            $dnsOk = $delivery['dns_ok'] ?? null;
            $dnsNote = (string) ($delivery['dns_note'] ?? $dnsNote);
            $mxOk = $delivery['mx_ok'] ?? null;
            $spfOk = $delivery['spf_ok'] ?? null;
            $dkimOk = $delivery['dkim_ok'] ?? null;
            $dmarcOk = $delivery['dmarc_ok'] ?? null;
            $authChecks = $delivery['checks'] ?? $authChecks;
            $ptrNote = (string) ($delivery['ptr_note'] ?? $ptrNote);
        } catch (\Throwable $e) {
            $dnsNote = 'Could not verify DNS';
        }

        return [
            'domain' => $domain ?? ($meta['mailcow_domain'] ?? $meta['domain'] ?? null),
            'mailbox_count' => $mailboxCount,
            'mailbox_limit' => (int) ($meta['mailbox_limit'] ?? $limits['mailboxes']),
            'msgs_per_day' => (int) ($meta['msgs_per_day'] ?? $limits['msgs_per_day']),
            'dns_ok' => $dnsOk,
            'dns_note' => $dnsNote,
            'mx_ok' => $mxOk,
            'spf_ok' => $spfOk,
            'dkim_ok' => $dkimOk,
            'dmarc_ok' => $dmarcOk,
            'auth_checks' => $authChecks,
            'ptr_note' => $ptrNote,
        ];
    }
}
