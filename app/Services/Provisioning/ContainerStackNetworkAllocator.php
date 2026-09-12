<?php

namespace App\Services\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\Node;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Hands each stack its own /24 so Docker never has to pick one.
 *
 * Docker's default address pools give a host about thirty user-defined
 * networks before `docker network create` fails with "all predefined address
 * pools have been fully subnetted". That is the failure the old shared bridge
 * was introduced to avoid, and a private network per stack walks straight
 * back into it. Widening the pool means editing daemon.json and restarting
 * Docker on every live node, which is an outage per node. Allocating the
 * subnet here costs nothing at runtime and works on every node today.
 *
 * Same shape as assignPort(): read what the node already uses under a row
 * lock, take the first free block, and let the unique index on
 * (node_id, network_subnet) be the final referee when two deploys race.
 */
class ContainerStackNetworkAllocator
{
    private const MAX_ATTEMPTS = 3;

    private int $baseLong;

    private int $baseSize;

    private int $blockSize;

    public function __construct(
        private string $base = '10.210.0.0/16',
        private int $prefix = 24,
    ) {
        [$ip, $basePrefix] = array_pad(explode('/', $this->base, 2), 2, null);
        $long = ip2long((string) $ip);
        $basePrefix = (int) $basePrefix;
        if ($long === false || $basePrefix < 8 || $basePrefix > 30) {
            throw new \InvalidArgumentException("Stack subnet base is not a usable IPv4 CIDR: {$this->base}");
        }
        if ($this->prefix <= $basePrefix || $this->prefix > 30) {
            throw new \InvalidArgumentException("Stack subnet prefix /{$this->prefix} must be longer than the base /{$basePrefix} and at most /30.");
        }

        $this->baseSize = 2 ** (32 - $basePrefix);
        $this->blockSize = 2 ** (32 - $this->prefix);
        // Snap to the block boundary so a sloppy base still yields valid subnets.
        $this->baseLong = $long - ($long % $this->baseSize);
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('containers.isolation.stack_subnet_base', '10.210.0.0/16'),
            (int) config('containers.isolation.stack_subnet_prefix', 24),
        );
    }

    public function capacity(): int
    {
        return intdiv($this->baseSize, $this->blockSize);
    }

    /**
     * The subnet this deployment renders with. Allocated once per node and
     * kept; a deployment that already has one is never moved.
     */
    public function ensureFor(ContainerDeployment $deployment): string
    {
        $current = trim((string) ($deployment->network_subnet ?? ''));
        if ($current !== '') {
            return $current;
        }

        $node = $deployment->node ?? Node::query()->find($deployment->node_id);
        if (! $node) {
            throw new \DomainException("Deployment {$deployment->id} has no container host to allocate a network on.");
        }

        for ($attempt = 1; ; $attempt++) {
            $subnet = $this->allocate($node, $deployment->id);

            try {
                $deployment->forceFill(['network_subnet' => $subnet])->save();

                return $subnet;
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $e;
                }
                // Another deploy on this node took the same block between our
                // read and our write. Read again; the lock only serialises
                // callers that hold rows, and a brand-new node has none.
            }
        }
    }

    /**
     * First free block on the node. Read under a row lock so concurrent
     * deploys on a populated node line up rather than both picking the same
     * block.
     */
    public function allocate(Node $node, ?int $ignoreDeploymentId = null): string
    {
        return DB::transaction(function () use ($node, $ignoreDeploymentId): string {
            $query = ContainerDeployment::query()
                ->where('node_id', $node->id)
                ->whereNotNull('network_subnet')
                ->lockForUpdate();
            if ($ignoreDeploymentId !== null) {
                $query->where('id', '!=', $ignoreDeploymentId);
            }

            $used = $query->pluck('network_subnet')
                ->map(static fn ($subnet): string => trim((string) $subnet))
                ->flip()
                ->all();

            foreach ($this->candidates() as $subnet) {
                if (! isset($used[$subnet])) {
                    return $subnet;
                }
            }

            $label = $node->hostname ?: ($node->name ?: (string) $node->id);

            // "no capacity" is a ProvisionFailureLedger capacity needle, so a
            // full node is treated like a full node, not like a config error.
            throw new \DomainException(
                "Container host '{$label}' has no capacity for another stack network: "
                ."subnet pool {$this->base} is exhausted ({$this->capacity()} blocks)."
            );
        });
    }

    /**
     * @return \Generator<int, string>
     */
    public function candidates(): \Generator
    {
        for ($offset = 0; $offset < $this->baseSize; $offset += $this->blockSize) {
            yield long2ip($this->baseLong + $offset).'/'.$this->prefix;
        }
    }
}
