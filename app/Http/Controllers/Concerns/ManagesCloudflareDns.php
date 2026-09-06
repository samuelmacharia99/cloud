<?php

namespace App\Http\Controllers\Concerns;

use App\Models\DnsZone;
use App\Models\Domain;
use App\Models\User;
use App\Services\Dns\DomainCloudflareDnsService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

trait ManagesCloudflareDns
{
    /**
     * @return array{
     *     domain: Domain,
     *     zone: DnsZone|null,
     *     records: Collection<int, array<string, mixed>>,
     *     usesDirectAdmin: bool,
     *     cloudflareAvailable: bool,
     *     canProvision: bool
     * }
     */
    protected function dnsPageData(Domain $domain, DomainCloudflareDnsService $dns, ?User $actor): array
    {
        if ($dns->hasDirectAdminDns($domain)) {
            return [
                'domain' => $domain,
                'zone' => null,
                'records' => collect(),
                'usesDirectAdmin' => true,
                'cloudflareAvailable' => false,
                'canProvision' => false,
            ];
        }

        $zone = $domain->dnsZones()->where('provider', 'cloudflare')->first();
        $records = collect();

        if ($dns->usesCloudflareDns($domain)) {
            $dns->refreshAssignedNameservers($domain, pushToRegistrar: true);
            $domain->refresh();
            $records = collect($dns->listRecords($domain));
        }

        return [
            'domain' => $domain,
            'zone' => $zone,
            'records' => $records,
            'usesDirectAdmin' => false,
            'cloudflareAvailable' => $dns->isAvailableForCustomer($actor),
            'canProvision' => $dns->shouldOfferCloudflareDns($domain, $actor)
                && ($domain->cloudflare_dns_enabled || $dns->isAvailableForCustomer($actor)),
        ];
    }

    /**
     * @return array{name: string, type: string, content: string, ttl?: int, priority?: int, proxied?: bool|null}
     */
    protected function validateDnsRecordPayload(Request $request): array
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
}
