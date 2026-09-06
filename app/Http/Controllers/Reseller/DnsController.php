<?php

namespace App\Http\Controllers\Reseller;

use App\Http\Controllers\Concerns\ManagesCloudflareDns;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Dns\DomainCloudflareDnsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DnsController extends Controller
{
    use ManagesCloudflareDns;

    public function __construct(
        private DomainCloudflareDnsService $dns,
    ) {}

    public function index(Domain $domain): View
    {
        $this->authorize('manageDns', $domain);

        $domain->concealUpstreamProviderDetails();

        return view('reseller.domains.dns.index', $this->dnsPageData($domain, $this->dns, auth()->user()));
    }

    public function provision(Domain $domain): RedirectResponse
    {
        $this->authorize('manageDns', $domain);

        $result = $this->dns->provisionZone($domain);

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        $flashKey = str_contains((string) $result['message'], 'did not complete') ? 'warning' : 'success';

        return redirect()
            ->route('reseller.domains.dns.index', $domain)
            ->with($flashKey, $result['message']);
    }

    public function addRecord(Request $request, Domain $domain): RedirectResponse
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

    public function updateRecord(Request $request, Domain $domain, string $recordId): RedirectResponse
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

    public function deleteRecord(Domain $domain, string $recordId): RedirectResponse
    {
        $this->authorize('manageDns', $domain);

        $result = $this->dns->deleteRecord($domain, $recordId);

        return $result['success']
            ? back()->with('success', 'DNS record deleted successfully.')
            : back()->with('error', $result['message']);
    }
}
