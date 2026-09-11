<?php

namespace App\Console\Commands;

use App\Exceptions\SSH\SSHConnectionException;
use App\Models\ContainerDeployment;
use App\Models\Service;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\Provisioning\WordPressDatabaseConfigAnalyzer;
use App\Services\SSH\SSHService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Which WordPress sites are one DNS round-robin away from going down.
 *
 * wp-config.php is written once, on first boot, and older deploys wrote
 * DB_HOST as the bare service name. Inside a single compose project that
 * resolves to the site's own database and looks fine for months. On a node
 * where every stack shares a network it resolves to whichever sidecar Docker
 * picked, and the site starts showing "Error establishing a database
 * connection" with healthy containers and nothing in its logs.
 *
 * One customer reporting it does not mean one site has it. This reads every
 * WordPress site and says how many are carrying the same fuse.
 *
 * Strictly read-only. It opens files and changes nothing.
 */
class AuditWordPressDatabaseHostCommand extends Command
{
    protected $signature = 'containers:audit-wordpress-db-host
        {--node= : Only audit services on this node id}
        {--at-risk : List only the sites that need fixing}';

    protected $description = 'Report WordPress sites whose wp-config.php points at a shared database hostname (read-only)';

    public function handle(WordPressDatabaseConfigAnalyzer $analyzer): int
    {
        $deployments = $this->deployments();

        if ($deployments->isEmpty()) {
            $this->info('No WordPress deployments to audit.');

            return self::SUCCESS;
        }

        $this->info("Auditing {$deployments->count()} WordPress deployment(s). Nothing is modified.");

        $rows = [];
        $atRisk = 0;
        $unreadable = 0;

        // Grouped by node so the fleet costs one SSH connection per host
        // rather than one per site.
        foreach ($deployments->groupBy('node_id') as $nodeId => $nodeDeployments) {
            $node = $nodeDeployments->first()->node;
            if (! $node) {
                continue;
            }

            $ssh = SSHService::forNode($node);
            $nodeIsReachable = true;

            try {
                foreach ($nodeDeployments as $deployment) {
                    // A node that refused the first connection will refuse the
                    // rest, and SSHService retries three times before it gives
                    // up. Dialling it once per site turns one dead host into a
                    // sweep nobody waits for.
                    $row = $nodeIsReachable
                        ? $this->auditDeployment($analyzer, $ssh, $deployment, (string) $node->hostname)
                        : $this->unreadableRow($deployment, (string) $node->hostname);

                    if ($row['verdict'] === 'at risk') {
                        $atRisk++;
                    } elseif ($row['verdict'] === 'unreadable') {
                        $unreadable++;
                    }

                    if ($nodeIsReachable && $row['unreachable_node'] !== null) {
                        $nodeIsReachable = false;
                        $this->warn("Node {$node->hostname} is unreachable, so its sites are reported unreadable: {$row['unreachable_node']}");
                    }

                    if (! $this->option('at-risk') || $row['verdict'] === 'at risk') {
                        $rows[] = $row;
                    }
                }
            } catch (\Throwable $e) {
                $this->warn("Node {$nodeId}: {$e->getMessage()}");
            } finally {
                $ssh->disconnect();
            }
        }

        if ($rows !== []) {
            $this->table(['Service', 'Site', 'Node', 'wp-config DB_HOST', 'Verdict'], array_map(
                static fn (array $row): array => [
                    $row['service_id'],
                    $row['name'],
                    $row['node'],
                    $row['host'],
                    $row['verdict'],
                ],
                $rows,
            ));
        }

        $this->newLine();
        $this->line('At risk: '.$atRisk);
        $this->line('Unreadable: '.$unreadable);

        if ($atRisk > 0) {
            $this->newLine();
            $this->warn('Each site above is served by whichever database Docker resolves that name to.');
            $this->line('Repair DB credentials on the service pins its own database in wp-config.php, or edit the define directly.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{service_id: int|string, name: string, node: string, host: string, verdict: string, unreachable_node: string|null}
     */
    private function auditDeployment(
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
        $row['verdict'] = $analyzer->hostIsShared($host)
            ? 'at risk'
            : 'pinned';

        if ($row['verdict'] === 'at risk') {
            $row['host'] = $host.' → should be '
                .app(ContainerDeploymentService::class)->sidecarDnsHost((string) $deployment->container_name);
        }

        return $row;
    }

    /**
     * A site the audit could not open a verdict on. Never "healthy": an audit
     * that reports a site it never read as fine is worse than no audit.
     *
     * @return array{service_id: int|string, name: string, node: string, host: string, verdict: string, unreachable_node: string|null}
     */
    private function unreadableRow(ContainerDeployment $deployment, string $nodeName): array
    {
        return [
            'service_id' => $deployment->service_id,
            'name' => (string) ($deployment->service?->name ?? $deployment->container_name),
            'node' => $nodeName,
            'host' => '—',
            'verdict' => 'unreadable',
            'unreachable_node' => null,
        ];
    }

    /**
     * @return Collection<int, ContainerDeployment>
     */
    private function deployments()
    {
        $query = ContainerDeployment::query()
            ->whereNotIn('status', ['terminated'])
            ->whereNotNull('node_id')
            ->with(['node', 'service.product.containerTemplate']);

        if ($node = $this->option('node')) {
            $query->where('node_id', (int) $node);
        }

        return $query->get()->filter(
            fn (ContainerDeployment $deployment): bool => $deployment->service instanceof Service
                && $deployment->service->isWordPressContainer()
        )->values();
    }
}
