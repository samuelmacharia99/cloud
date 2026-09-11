<?php

namespace App\Services\Provisioning;

use App\Exceptions\SSH\SSHConnectionException;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\SSH\SSHService;
use Illuminate\Support\Collection;

/**
 * Which WordPress sites are resolving a database hostname that is not theirs.
 *
 * wp-config.php is written once, on first boot, and older deploys wrote
 * DB_HOST as the bare service name. On a node where every stack shares one
 * network that name is an alias every WordPress sidecar answers to, so Docker
 * hands out whichever it picks. The site is not broken and not working: it
 * serves "Error establishing a database connection" at random and recovers on
 * its own, which is why it never produces a clean bug report.
 *
 * Reading that state and repairing it are two commands, but they have to agree
 * on which sites are affected, so the sweep lives here rather than in either.
 */
class WordPressDatabaseHostAudit
{
    public const AT_RISK = 'at risk';

    public const PINNED = 'pinned';

    public const UNREADABLE = 'unreadable';

    /**
     * Walk the fleet a node at a time, handing each site's verdict to $onRow.
     *
     * Grouped by node so the sweep costs one SSH connection per host rather
     * than one per site, and a host that refuses the first connection is not
     * dialled again: SSHService retries three times before it gives up, so
     * re-dialling a dead node for every site it carries is how a sweep stops
     * finishing.
     *
     * @param  callable(array<string, mixed>): void  $onRow
     * @param  callable(string, string): void|null  $onNodeUnreachable
     */
    public function sweep(
        WordPressDatabaseConfigAnalyzer $analyzer,
        ?int $nodeId,
        callable $onRow,
        ?callable $onNodeUnreachable = null,
    ): void {
        foreach ($this->deployments($nodeId)->groupBy('node_id') as $nodeDeployments) {
            $node = $nodeDeployments->first()->node;
            if (! $node) {
                continue;
            }

            $ssh = SSHService::forNode($node);
            $nodeIsReachable = true;

            try {
                foreach ($nodeDeployments as $deployment) {
                    $row = $nodeIsReachable
                        ? $this->inspect($analyzer, $ssh, $deployment, (string) $node->hostname)
                        : $this->unreadableRow($deployment, (string) $node->hostname);

                    if ($nodeIsReachable && $row['unreachable_node'] !== null) {
                        $nodeIsReachable = false;
                        if ($onNodeUnreachable !== null) {
                            $onNodeUnreachable((string) $node->hostname, $row['unreachable_node']);
                        }
                    }

                    $onRow($row);
                }
            } finally {
                $ssh->disconnect();
            }
        }
    }

    /**
     * The verdict for one site, read from the file WordPress actually loads.
     *
     * @return array<string, mixed>
     */
    public function inspect(
        WordPressDatabaseConfigAnalyzer $analyzer,
        SSHService $ssh,
        ContainerDeployment $deployment,
        string $nodeName,
    ): array {
        $row = $this->unreadableRow($deployment, $nodeName);

        try {
            $credentials = $analyzer->effectiveCredentials($ssh, (string) $deployment->container_name);
        } catch (SSHConnectionException $e) {
            // The host, not the site. Every other site on it will fail the same
            // way, and the caller stops dialling once it sees this.
            $row['unreachable_node'] = mb_substr(trim($e->getMessage()), 0, 200);

            return $row;
        } catch (\Throwable) {
            return $row;
        }

        $host = trim((string) ($credentials['DB_HOST'] ?? ''));
        if ($host === '') {
            // No wp-config yet, or no DB_HOST in it. Not a verdict either way.
            return $row;
        }

        $row['host'] = $host;
        $row['verdict'] = $analyzer->hostIsShared($host) ? self::AT_RISK : self::PINNED;

        return $row;
    }

    /**
     * The name this stack's own database answers to, and nothing else does.
     */
    public function shouldBe(ContainerDeployment $deployment): string
    {
        return app(ContainerDeploymentService::class)
            ->sidecarDnsHost((string) $deployment->container_name);
    }

    /**
     * @return Collection<int, ContainerDeployment>
     */
    public function deployments(?int $nodeId = null): Collection
    {
        $query = ContainerDeployment::query()
            ->whereNotIn('status', ['terminated'])
            ->whereNotNull('node_id')
            ->with(['node', 'service.product.containerTemplate']);

        if ($nodeId !== null) {
            $query->where('node_id', $nodeId);
        }

        return $query->get()->filter(
            fn (ContainerDeployment $deployment): bool => $deployment->service instanceof Service
                && $deployment->service->isWordPressContainer()
        )->values();
    }

    /**
     * A site nothing could be read from. Never "pinned": reporting a site that
     * was never opened as healthy is worse than reporting nothing at all.
     *
     * @return array<string, mixed>
     */
    private function unreadableRow(ContainerDeployment $deployment, string $nodeName): array
    {
        return [
            'deployment' => $deployment,
            'service_id' => $deployment->service_id,
            'name' => (string) ($deployment->service?->name ?? $deployment->container_name),
            'node' => $nodeName,
            'host' => '',
            'verdict' => self::UNREADABLE,
            'unreachable_node' => null,
        ];
    }
}
