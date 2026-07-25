<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Services\Provisioning\MailcowMailboxAccessService;
use App\Services\Provisioning\MailcowProvisioningService;
use App\Services\Provisioning\MailDnsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EmailHostingController extends Controller
{
    public function show(Service $service, MailcowProvisioningService $provisioning, MailDnsService $dns): View|RedirectResponse
    {
        $this->authorize('manageEmailHosting', $service);

        if (! $service->isEmailHosting()) {
            return redirect()->route('customer.services.show', $service);
        }

        $domain = null;
        $mailboxes = [];
        $aliases = [];
        $connection = [];
        $dnsRecords = [];
        $error = null;
        $health = null;

        try {
            $domain = $provisioning->domainForService($service);
            $client = $provisioning->clientForService($service);
            $connection = $provisioning->connectionSettings($service);
            $dnsRecords = $dns->recommendedRecords($service);
            $health = app(\App\Services\Customer\CustomerNextStepsService::class)->emailHealth($service);

            $mb = $client->listMailboxes($domain);
            if ($mb['success']) {
                $mailboxes = $mb['data'] ?? [];
            }

            $al = $client->listAliases($domain);
            if ($al['success']) {
                $aliases = $al['data'] ?? [];
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $limits = $provisioning->limitsForProduct($service->product);

        return view('customer.services.email', [
            'service' => $service->load('product', 'node'),
            'mailDomain' => $domain,
            'mailboxes' => $mailboxes,
            'aliases' => $aliases,
            'connection' => $connection,
            'dnsRecords' => $dnsRecords,
            'health' => $health,
            'limits' => [
                'mailboxes' => (int) ($meta['mailbox_limit'] ?? $limits['mailboxes']),
                'aliases' => (int) ($meta['alias_limit'] ?? $limits['aliases']),
                'mailbox_quota_mb' => (int) ($meta['mailbox_quota_mb'] ?? $limits['mailbox_quota_mb']),
                'msgs_per_day' => (int) ($meta['msgs_per_day'] ?? $limits['msgs_per_day']),
            ],
            'error' => $error,
        ]);
    }

    public function storeMailbox(Request $request, Service $service, MailcowProvisioningService $provisioning): RedirectResponse
    {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9._+-]+$/i'],
            'name' => ['nullable', 'string', 'max:120'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'quota_mb' => ['nullable', 'integer', 'min:100', 'max:102400'],
        ]);

        try {
            $domain = $provisioning->domainForService($service);
            $client = $provisioning->clientForService($service);
            $limits = $provisioning->limitsForProduct($service->product);

            $listed = $client->listMailboxes($domain);
            $count = count($listed['data'] ?? []);
            if ($count >= $limits['mailboxes']) {
                return back()->withErrors(['error' => 'Mailbox limit reached for this plan ('.$limits['mailboxes'].').']);
            }

            $quota = (int) ($validated['quota_mb'] ?? $limits['mailbox_quota_mb']);
            $quota = min($quota, $limits['mailbox_quota_mb']);

            $result = $client->addMailbox([
                'local_part' => strtolower($validated['local_part']),
                'domain' => $domain,
                'name' => $validated['name'] ?? $validated['local_part'],
                'password' => $validated['password'],
                'password2' => $validated['password'],
                'quota' => (string) $quota,
                'active' => '1',
                'force_pw_update' => '0',
                'tls_enforce_in' => '1',
                'tls_enforce_out' => '1',
            ]);

            if (! $result['success']) {
                return back()->withErrors(['error' => $result['message']])->withInput();
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Mailbox created.');
    }

    public function destroyMailbox(Request $request, Service $service, MailcowProvisioningService $provisioning): RedirectResponse
    {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $domain = $provisioning->domainForService($service);
            $email = strtolower($validated['email']);
            if (! str_ends_with($email, '@'.$domain)) {
                return back()->withErrors(['error' => 'Mailbox does not belong to this mail domain.']);
            }

            $result = $provisioning->clientForService($service)->deleteMailbox($email);
            if (! $result['success']) {
                return back()->withErrors(['error' => $result['message']]);
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('success', 'Mailbox deleted.');
    }

    public function storeAlias(Request $request, Service $service, MailcowProvisioningService $provisioning): RedirectResponse
    {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'address' => ['required', 'string', 'max:190'],
            'goto' => ['required', 'string', 'max:500'],
        ]);

        try {
            $domain = $provisioning->domainForService($service);
            $address = strtolower(trim($validated['address']));
            if (! str_contains($address, '@')) {
                $address .= '@'.$domain;
            }
            if (! str_ends_with($address, '@'.$domain)) {
                return back()->withErrors(['error' => 'Alias address must use @'.$domain])->withInput();
            }

            $result = $provisioning->clientForService($service)->addAlias([
                'address' => $address,
                'goto' => $validated['goto'],
                'active' => '1',
            ]);

            if (! $result['success']) {
                return back()->withErrors(['error' => $result['message']])->withInput();
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        return back()->with('success', 'Alias created.');
    }

    public function destroyAlias(Request $request, Service $service, MailcowProvisioningService $provisioning): RedirectResponse
    {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'id' => ['required'],
        ]);

        try {
            $result = $provisioning->clientForService($service)->deleteAlias($validated['id']);
            if (! $result['success']) {
                return back()->withErrors(['error' => $result['message']]);
            }
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('success', 'Alias deleted.');
    }

    public function applyDns(Service $service, MailDnsService $dns): RedirectResponse
    {
        $this->authorize('manageEmailHosting', $service);

        $result = $dns->applyRecommendedRecords($service);

        if ($result['success']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('info', $result['message']);
    }

    public function openMailbox(
        Request $request,
        Service $service,
        MailcowMailboxAccessService $access
    ): RedirectResponse {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $result = $access->issueWebmailSso($service, $validated['email']);

        if (! $result['success']) {
            return back()->withErrors(['error' => $result['message']]);
        }

        return redirect()->away($result['redirect']);
    }

    public function testDelivery(
        Request $request,
        Service $service,
        MailcowMailboxAccessService $access
    ): RedirectResponse {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'from' => ['required', 'email'],
            'to' => ['required', 'email'],
        ]);

        $result = $access->sendDeliveryTest($service, $validated['from'], $validated['to']);

        if (! $result['success']) {
            return back()->withErrors(['error' => $result['message']])->withInput();
        }

        return back()->with('success', $result['message']);
    }

    public function updateMailboxPassword(
        Request $request,
        Service $service,
        \App\Services\Provisioning\MailcowMailboxOpsService $ops
    ): RedirectResponse {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ]);

        try {
            $result = $ops->changePassword($service, $validated['email'], $validated['password']);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        if (! $result['success']) {
            return back()->withErrors(['error' => $result['message']])->withInput();
        }

        return back()->with('success', $result['message'])->with('tab', 'mailboxes');
    }

    public function updateMailboxName(
        Request $request,
        Service $service,
        \App\Services\Provisioning\MailcowMailboxOpsService $ops
    ): RedirectResponse {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $ops->updateDisplayName($service, $validated['email'], $validated['name']);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        if (! $result['success']) {
            return back()->withErrors(['error' => $result['message']])->withInput();
        }

        return back()->with('success', $result['message']);
    }

    public function enableVacation(
        Request $request,
        Service $service,
        \App\Services\Provisioning\MailcowMailboxOpsService $ops
    ): RedirectResponse {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        try {
            $result = $ops->enableVacation(
                $service,
                $validated['email'],
                $validated['subject'],
                $validated['body'],
                (int) ($validated['days'] ?? 1),
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        if (! $result['success']) {
            return back()->withErrors(['error' => $result['message']])->withInput();
        }

        return back()->with('success', $result['message']);
    }

    public function disableVacation(
        Request $request,
        Service $service,
        \App\Services\Provisioning\MailcowMailboxOpsService $ops
    ): RedirectResponse {
        $this->authorize('manageEmailHosting', $service);

        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        try {
            $result = $ops->disableVacation($service, $validated['email']);
        } catch (\Throwable $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        if (! $result['success']) {
            return back()->withErrors(['error' => $result['message']]);
        }

        return back()->with('success', $result['message']);
    }
}
