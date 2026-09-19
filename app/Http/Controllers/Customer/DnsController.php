<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Concerns\ManagesCloudflareDns;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Dns\CloudflareZoneStateService;
use App\Services\Dns\DomainCloudflareDnsService;
use App\Services\Registrar\RegistrarFulfillmentService;
use Illuminate\Http\Request;

class DnsController extends Controller
{
    use ManagesCloudflareDns;

    public function __construct(
        private DomainCloudflareDnsService $dns,
    ) {}

    public function index(Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        return view('customer.domains.dns.index', $this->dnsPageData($domain, $this->dns, auth()->user()));
    }

    public function provision(Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        $result = $this->dns->provisionZone($domain);

        // The action the operator just pressed is the moment to ask
        // Cloudflare where the zone stands, rather than the next page load.
        app(CloudflareZoneStateService::class)->refresh($domain->fresh());

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        return redirect()
            ->route('customer.domains.dns.index', $domain)
            ->with('success', $result['message']);
    }

    public function nameservers(Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        return view('customer.domains.dns.nameservers', [
            'domain' => $domain,
            'usesDirectAdmin' => $this->dns->hasDirectAdminDns($domain),
        ]);
    }

    public function updateNameservers(Request $request, Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        if ($domain->isDnsManaged()) {
            return back()->with('error', 'DNS-only domains do not have registry nameservers to change.');
        }

        $validated = $request->validate([
            'nameserver_1' => 'required|string|min:3|max:253',
            'nameserver_2' => 'required|string|min:3|max:253',
            'nameserver_3' => 'nullable|string|min:3|max:253',
            'nameserver_4' => 'nullable|string|min:3|max:253',
        ]);

        $result = app(RegistrarFulfillmentService::class)->updateDomainNameservers($domain, [
            'ns1' => $validated['nameserver_1'],
            'ns2' => $validated['nameserver_2'],
            'ns3' => $validated['nameserver_3'] ?? null,
            'ns4' => $validated['nameserver_4'] ?? null,
        ]);

        if (! $result['success']) {
            return back()->with('error', app(RegistrarFulfillmentService::class)->concealProviderMessage($result['message']))->withInput();
        }

        $domain->update([
            'nameserver_1' => $validated['nameserver_1'],
            'nameserver_2' => $validated['nameserver_2'],
            'nameserver_3' => $validated['nameserver_3'] ?? null,
            'nameserver_4' => $validated['nameserver_4'] ?? null,
        ]);

        $flashKey = $result['pushed'] ? 'success' : 'warning';

        return back()->with($flashKey, app(RegistrarFulfillmentService::class)->concealProviderMessage($result['message']));
    }

    public function addRecord(Request $request, Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        $validated = $this->validateDnsRecordPayload($request);

        try {
            $result = $this->dns->addRecord(
                $domain,
                $validated['name'],
                $validated['type'],
                $validated['content'],
                (int) ($validated['ttl'] ?? 3600),
                isset($validated['priority']) ? (int) $validated['priority'] : null,
                $validated['proxied'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return $result['success']
            ? back()->with('success', 'DNS record added successfully.')
            : back()->with('error', $result['message'])->withInput();
    }

    public function updateRecord(Request $request, Domain $domain, string $recordId)
    {
        $this->authorize('manageDns', $domain);

        $validated = $this->validateDnsRecordPayload($request);

        try {
            $result = $this->dns->updateRecord(
                $domain,
                $recordId,
                $validated['name'],
                $validated['type'],
                $validated['content'],
                (int) ($validated['ttl'] ?? 3600),
                isset($validated['priority']) ? (int) $validated['priority'] : null,
                $validated['proxied'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        return $result['success']
            ? back()->with('success', 'DNS record updated successfully.')
            : back()->with('error', $result['message'])->withInput();
    }

    public function deleteRecord(Domain $domain, string $recordId)
    {
        $this->authorize('manageDns', $domain);

        $result = $this->dns->deleteRecord($domain, $recordId);

        return $result['success']
            ? back()->with('success', 'DNS record deleted successfully.')
            : back()->with('error', $result['message']);
    }
}
