<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Dns\DomainCloudflareDnsService;
use Illuminate\Http\Request;

class DnsController extends Controller
{
    public function __construct(
        private DomainCloudflareDnsService $dns,
    ) {}

    public function index(Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        if ($this->dns->hasDirectAdminDns($domain)) {
            return view('customer.domains.dns.index', [
                'domain' => $domain,
                'zone' => null,
                'records' => collect(),
                'usesDirectAdmin' => true,
                'cloudflareAvailable' => false,
                'canProvision' => false,
            ]);
        }

        $zone = $domain->dnsZones()->where('provider', 'cloudflare')->first();
        $records = collect();

        if ($this->dns->usesCloudflareDns($domain)) {
            $records = collect($this->dns->listRecords($domain));
        }

        return view('customer.domains.dns.index', [
            'domain' => $domain,
            'zone' => $zone,
            'records' => $records,
            'usesDirectAdmin' => false,
            'cloudflareAvailable' => $this->dns->isAvailable(),
            'canProvision' => $this->dns->shouldOfferCloudflareDns($domain)
                && ($domain->cloudflare_dns_enabled || $this->dns->isAvailable()),
        ]);
    }

    public function provision(Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        $result = $this->dns->provisionZone($domain);

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

        if ($this->dns->usesCloudflareDns($domain)) {
            return back()->with('error', 'Nameservers are managed automatically for Cloudflare DNS zones. Update branded nameservers in admin settings if needed.');
        }

        $validated = $request->validate([
            'nameserver_1' => 'required|string|min:3|max:253',
            'nameserver_2' => 'nullable|string|min:3|max:253',
            'nameserver_3' => 'nullable|string|min:3|max:253',
            'nameserver_4' => 'nullable|string|min:3|max:253',
        ]);

        $domain->update([
            'nameserver_1' => $validated['nameserver_1'],
            'nameserver_2' => $validated['nameserver_2'] ?? null,
            'nameserver_3' => $validated['nameserver_3'] ?? null,
            'nameserver_4' => $validated['nameserver_4'] ?? null,
        ]);

        return back()->with('success', 'Nameservers updated successfully. Changes may take up to 48 hours to propagate.');
    }

    public function addRecord(Request $request, Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        $validated = $this->validateRecordPayload($request);

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

        $validated = $this->validateRecordPayload($request);

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

    /**
     * @return array{name: string, type: string, content: string, ttl?: int, priority?: int, proxied?: bool|null}
     */
    private function validateRecordPayload(Request $request): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:A,AAAA,CNAME,MX,TXT,NS,SRV,CAA',
            'content' => 'required|string|max:2000',
            'ttl' => 'nullable|integer|min:1|max:86400',
            'priority' => 'nullable|integer|min:0|max:65535',
            'proxied' => 'nullable|boolean',
        ]);

        $proxyable = in_array($validated['type'], ['A', 'AAAA', 'CNAME'], true);
        if (array_key_exists('proxied', $validated)) {
            $validated['proxied'] = $proxyable ? $request->boolean('proxied') : null;
        } else {
            $validated['proxied'] = null;
        }

        if (! empty($validated['proxied'])) {
            $validated['ttl'] = 1;
        }

        return $validated;
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
