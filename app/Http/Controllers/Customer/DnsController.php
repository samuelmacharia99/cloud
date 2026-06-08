<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DnsRecord;
use App\Models\Domain;
use Illuminate\Http\Request;

class DnsController extends Controller
{
    /**
     * Show DNS management for a domain
     */
    public function index(Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        $zone = $domain->dnsZones()->first();
        $records = $zone ? $zone->records()->orderBy('type')->get() : collect();

        return view('customer.domains.dns.index', compact('domain', 'zone', 'records'));
    }

    /**
     * Show nameserver settings
     */
    public function nameservers(Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        return view('customer.domains.dns.nameservers', compact('domain'));
    }

    /**
     * Update nameservers
     */
    public function updateNameservers(Request $request, Domain $domain)
    {
        $this->authorize('manageDns', $domain);

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

    /**
     * Show DNS records for a zone
     */
    public function records(Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        $zone = $domain->dnsZones()->firstOrFail();
        $records = $zone->records()->orderBy('type')->get();

        return view('customer.domains.dns.records', compact('domain', 'zone', 'records'));
    }

    /**
     * Add a DNS record
     */
    public function addRecord(Request $request, Domain $domain)
    {
        $this->authorize('manageDns', $domain);

        $validated = $request->validate([
            'name' => 'required|string',
            'type' => 'required|in:A,AAAA,CNAME,MX,TXT,NS,SRV,CAA',
            'content' => 'required|string',
            'ttl' => 'nullable|integer|min:300|max:86400',
            'priority' => 'nullable|integer',
        ]);

        try {
            $zone = $domain->dnsZones()->firstOrFail();

            DnsRecord::create([
                'dns_zone_id' => $zone->id,
                'name' => $validated['name'],
                'type' => $validated['type'],
                'content' => $validated['content'],
                'ttl' => $validated['ttl'] ?? 3600,
                'priority' => $validated['priority'] ?? null,
            ]);

            return back()->with('success', 'DNS record added successfully');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Update a DNS record
     */
    public function updateRecord(Request $request, Domain $domain, DnsRecord $record)
    {
        $this->authorize('manageDns', $domain);
        abort_if($record->dnsZone->domain_id !== $domain->id, 403);

        $validated = $request->validate([
            'content' => 'required|string',
            'ttl' => 'nullable|integer|min:300|max:86400',
            'priority' => 'nullable|integer',
        ]);

        try {
            $record->update([
                'content' => $validated['content'],
                'ttl' => $validated['ttl'] ?? 3600,
                'priority' => $validated['priority'] ?? null,
            ]);

            return back()->with('success', 'DNS record updated successfully');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Delete a DNS record
     */
    public function deleteRecord(Domain $domain, DnsRecord $record)
    {
        $this->authorize('manageDns', $domain);
        abort_if($record->dnsZone->domain_id !== $domain->id, 403);

        try {
            $record->delete();

            return back()->with('success', 'DNS record deleted successfully');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
