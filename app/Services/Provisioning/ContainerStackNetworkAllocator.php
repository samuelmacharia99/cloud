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
    public function ensureFor(ContainerDeployment $deployment, array $liveUsed = []): string
    {
        $current = trim((string) ($deployment->network_subnet ?? ''));
        if ($current !== '') {
            return $current;
        }

        return $this->reallocate($deployment, $liveUsed);
    }

    /**
     * Give the deployment a fresh block, skipping what the database and the
     * node itself say is taken. Used when a network on the node already holds
     * the block the row carries (a stale stack, or a network nobody recorded).
     *
     * @param  list<string>  $liveUsed  subnets seen on the node right now
     */
    public function reallocate(ContainerDeployment $deployment, array $liveUsed = []): string
    {
        $node = $deployment->node ?? Node::query()->find($deployment->node_id);
        if (! $node) {
            throw new \DomainException("Deployment {$deployment->id} has no container host to allocate a network on.");
        }

        for ($attempt = 1; ; $attempt++) {
            $subnet = $this->allocate($node, $deployment->id, $liveUsed);

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
    public function allocate(Node $node, ?int $ignoreDeploymentId = null, array $liveUsed = []): string
    {
        return DB::transaction(function () use ($node, $ignoreDeploymentId, $liveUsed): string {
            $query = ContainerDeployment::query()
                ->where('node_id', $node->id)
                ->whereNotNull('network_subnet')
                ->lockForUpdate();
            if ($ignoreDeploymentId !== null) {
                $query->where('id', '!=', $ignoreDeploymentId);
            }

            // Ranges, not strings. Docker refuses a block that *overlaps* an
            // existing network, so comparing the text of two CIDRs misses every
            // conflict that is not character-for-character identical: a wider
            // network sitting over the block, or a longer prefix inside it,
            // both read as free here and are then rejected by the daemon.
            $taken = [];
            foreach ($query->pluck('network_subnet') as $subnet) {
                if ($range = self::rangeOf((string) $subnet)) {
                    $taken[] = $range;
                }
            }
            foreach ($liveUsed as $subnet) {
                if ($range = self::rangeOf((string) $subnet)) {
                    $taken[] = $range;
                }
            }

            foreach ($this->candidates() as $subnet) {
                $range = self::rangeOf($subnet);
                if ($range === null) {
                    continue;
                }

                foreach ($taken as $other) {
                    if ($range[0] <= $other[1] && $other[0] <= $range[1]) {
                        continue 2;
                    }
                }

                return $subnet;
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
     * Whether two CIDRs share any address. This is the test Docker applies
     * when it accepts or rejects a network.
     */
    public function overlaps(string $a, string $b): bool
    {
        $left = self::rangeOf($a);
        $right = self::rangeOf($b);

        if ($left === null || $right === null) {
            return false;
        }

        return $left[0] <= $right[1] && $right[0] <= $left[1];
    }

    /**
     * First and last address of a CIDR, as integers.
     *
     * IPv6 and anything unparseable yield null: the pool is IPv4, so a network
     * we cannot read cannot be shown to conflict with it.
     *
     * @return array{int, int}|null
     */
    public static function rangeOf(string $cidr): ?array
    {
        [$ip, $prefix] = array_pad(explode('/', trim($cidr), 2), 2, null);

        $long = ip2long((string) $ip);
        if ($long === false || $prefix === null || ! is_numeric($prefix)) {
            return null;
        }

        $prefix = (int) $prefix;
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }

        $size = 2 ** (32 - $prefix);
        $start = $long - ($long % $size);

        return [$start, $start + $size - 1];
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
