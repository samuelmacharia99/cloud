<?php

namespace App\Services;

use App\Models\Node;
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
            return $this->normalize(
                $node->nameserver_1,
                $node->nameserver_2,
                $node->nameserver_3,
                $node->nameserver_4,
            );
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
     * Default nameservers for standalone domain orders (first active DirectAdmin node).
     *
     * @return array{ns1: string, ns2: ?string, ns3: ?string, ns4: ?string}
     */
    public function platformDefaults(): array
    {
        $node = Node::query()
            ->where('type', 'directadmin')
            ->where('is_active', true)
            ->whereNotNull('nameserver_1')
            ->where('nameserver_1', '!=', '')
            ->orderBy('name')
            ->first();

        if ($node) {
            return $this->normalize(
                $node->nameserver_1,
                $node->nameserver_2,
                $node->nameserver_3,
                $node->nameserver_4,
            );
        }

        return $this->normalize(
            Setting::getValue('domain_ns1', 'ns1.talksasa.cloud'),
            Setting::getValue('domain_ns2', 'ns2.talksasa.cloud'),
            Setting::getValue('domain_ns3') ?: null,
            Setting::getValue('domain_ns4') ?: null,
        );
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
    protected function normalize(?string $ns1, ?string $ns2, ?string $ns3, ?string $ns4): array
    {
        return [
            'ns1' => trim((string) $ns1),
            'ns2' => $ns2 ? trim($ns2) : null,
            'ns3' => $ns3 ? trim($ns3) : null,
            'ns4' => $ns4 ? trim($ns4) : null,
        ];
    }
}
