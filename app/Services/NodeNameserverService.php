<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Node;
use App\Models\Service;
use App\Models\Setting;

class NodeNameserverService
{
    /**
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function forNode(?Node $node): array
    {
        if ($node === null) {
            return $this->platformDefaults();
        }

        if ($node->nameserver_1) {
            return $this->ensureMinimumNameservers($this->normalize(
                $node->nameserver_1,
                $node->nameserver_2,
                $node->nameserver_3,
                $node->nameserver_4,
            ));
        }

        return $this->platformDefaults();
    }

    /**
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function forNodeId(?int $nodeId): array
    {
        if (! $nodeId) {
            return $this->platformDefaults();
        }

        return $this->forNode(Node::find($nodeId));
    }

    /**
     * Resolve nameservers for registrar submission.
     * Linked hosting node (live config) → domain NS columns → platform defaults.
     *
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function forDomain(Domain $domain): array
    {
        if ($domain->cloudflare_dns_enabled) {
            $fromCloudflare = app(\App\Services\Dns\DomainCloudflareDnsService::class)->nameserversForRegistration();
            if ($fromCloudflare['ns1'] !== '') {
                return $this->ensureMinimumNameservers($fromCloudflare);
            }
        }

        $service = $this->findLinkedHostingService($domain);
        $nodeId = $this->resolveNodeId($service);

        if ($nodeId) {
            $fromNode = $this->forNodeId($nodeId);
            if ($fromNode['ns1'] !== '') {
                return $fromNode;
            }
        }

        if ($domain->nameserver_1) {
            return $this->ensureMinimumNameservers($this->normalize(
                $domain->nameserver_1,
                $domain->nameserver_2,
                $domain->nameserver_3,
                $domain->nameserver_4,
            ));
        }

        return $this->platformDefaults();
    }

    /**
     * @return list<string>
     */
    public function uniqueList(array $nameservers): array
    {
        $normalized = $this->normalize(
            $nameservers['ns1'] ?? '',
            $nameservers['ns2'] ?? null,
            $nameservers['ns3'] ?? null,
            $nameservers['ns4'] ?? null,
        );

        return array_values(array_filter([
            $normalized['ns1'] ?: null,
            $normalized['ns2'],
            $normalized['ns3'],
            $normalized['ns4'],
        ]));
    }

    /**
     * Default nameservers for standalone domain orders (first active node with two unique NS).
     *
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function platformDefaults(): array
    {
        foreach (['directadmin', 'container_host'] as $type) {
            $nodes = Node::query()
                ->where('type', $type)
                ->where('is_active', true)
                ->whereNotNull('nameserver_1')
                ->where('nameserver_1', '!=', '')
                ->orderBy('name')
                ->get();

            foreach ($nodes as $node) {
                $normalized = $this->normalize(
                    $node->nameserver_1,
                    $node->nameserver_2,
                    $node->nameserver_3,
                    $node->nameserver_4,
                );

                if (count($this->uniqueList($normalized)) >= 2) {
                    return $normalized;
                }
            }
        }

        return $this->ensureMinimumNameservers($this->normalize(
            Setting::getValue('domain_ns1', 'ns1.talksasa.cloud'),
            Setting::getValue('domain_ns2', 'ns2.talksasa.cloud'),
            Setting::getValue('domain_ns3') ?: null,
            Setting::getValue('domain_ns4') ?: null,
        ));
    }

    /**
     * @param  array{ns1: string, ns2?: ?string, ns3?: ?string, ns4?: ?string}  $nameservers
     * @return array{nameserver_1: string, nameserver_2: ?string, nameserver_3: ?string, nameserver_4: ?string}
     */
    public function toDomainColumns(array $nameservers): array
    {
        $ns = $this->normalize(
            $nameservers['ns1'] ?? '',
            $nameservers['ns2'] ?? null,
            $nameservers['ns3'] ?? null,
            $nameservers['ns4'] ?? null,
        );

        return [
            'nameserver_1' => $ns['ns1'],
            'nameserver_2' => $ns['ns2'],
            'nameserver_3' => $ns['ns3'],
            'nameserver_4' => $ns['ns4'],
        ];
    }

    /**
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function normalize(?string $ns1, ?string $ns2, ?string $ns3, ?string $ns4): array
    {
        $packed = [];
        $seen = [];

        foreach ([$ns1, $ns2, $ns3, $ns4] as $value) {
            $value = trim((string) ($value ?? ''));
            if ($value === '') {
                continue;
            }

            $lower = strtolower($value);
            if (isset($seen[$lower])) {
                continue;
            }

            $seen[$lower] = true;
            $packed[] = $value;
        }

        return [
            'ns1' => $packed[0] ?? '',
            'ns2' => $packed[1] ?? null,
            'ns3' => $packed[2] ?? null,
            'ns4' => $packed[3] ?? null,
        ];
    }

    private function findLinkedHostingService(Domain $domain): ?Service
    {
        $fqdn = strtolower($domain->name.($domain->extension ?? ''));

        $hosting = Service::query()
            ->where('user_id', $domain->user_id)
            ->whereHas('product', fn ($query) => $query->whereIn('type', ['container_hosting', 'shared_hosting']))
            ->where(function ($query) use ($domain, $fqdn) {
                $query->whereJsonContains('service_meta->domain_id', $domain->id)
                    ->orWhere('name', $fqdn)
                    ->orWhere('service_meta->primary_domain', $fqdn);
            })
            ->orderByDesc('id')
            ->first();

        if ($hosting) {
            return $hosting;
        }

        $invoiceIds = Service::query()
            ->where('user_id', $domain->user_id)
            ->whereJsonContains('service_meta->domain_id', $domain->id)
            ->whereNotNull('invoice_id')
            ->pluck('invoice_id');

        if ($invoiceIds->isEmpty()) {
            return null;
        }

        return Service::query()
            ->where('user_id', $domain->user_id)
            ->whereIn('invoice_id', $invoiceIds)
            ->whereHas('product', fn ($query) => $query->whereIn('type', ['container_hosting', 'shared_hosting']))
            ->orderByDesc('id')
            ->first();
    }

    private function resolveNodeId(?Service $service): ?int
    {
        if ($service === null) {
            return null;
        }

        if ($service->node_id) {
            return (int) $service->node_id;
        }

        $deployment = $service->relationLoaded('containerDeployment')
            ? $service->containerDeployment
            : $service->containerDeployment()->first();

        if ($deployment?->node_id) {
            return (int) $deployment->node_id;
        }

        return null;
    }

    /**
     * Openprovider requires two unique nameservers. Fill NS2 from platform settings when missing.
     *
     * @param  array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}  $nameservers
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    private function ensureMinimumNameservers(array $nameservers): array
    {
        if (count($this->uniqueList($nameservers)) >= 2) {
            return $nameservers;
        }

        if ($nameservers['ns1'] === '') {
            return $nameservers;
        }

        $fallbackNs2 = trim((string) Setting::getValue('domain_ns2', 'ns2.talksasa.cloud'));
        if ($fallbackNs2 !== '' && strtolower($fallbackNs2) !== strtolower($nameservers['ns1'])) {
            return $this->normalize(
                $nameservers['ns1'],
                $fallbackNs2,
                $nameservers['ns3'],
                $nameservers['ns4'],
            );
        }

        $fallbackNs1 = trim((string) Setting::getValue('domain_ns1', 'ns1.talksasa.cloud'));
        if ($fallbackNs1 !== '' && strtolower($fallbackNs1) !== strtolower($nameservers['ns1'])) {
            return $this->normalize(
                $nameservers['ns1'],
                $fallbackNs1,
                $nameservers['ns3'],
                $nameservers['ns4'],
            );
        }

        return $nameservers;
    }
}
