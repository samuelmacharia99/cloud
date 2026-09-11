<?php

namespace App\Console\Commands;

use App\Services\Provisioning\WordPressDatabaseConfigAnalyzer;
use App\Services\Provisioning\WordPressDatabaseHostAudit;
use Illuminate\Console\Command;

/**
 * How many WordPress sites are one DNS answer away from a database that is not
 * theirs. See WordPressDatabaseHostAudit for why that happens.
 *
 * Strictly read-only. It opens files and changes nothing.
 */
class AuditWordPressDatabaseHostCommand extends Command
{
    protected $signature = 'containers:audit-wordpress-db-host
        {--node= : Only audit services on this node id}
        {--at-risk : List only the sites that need fixing}';

    protected $description = 'Report WordPress sites whose wp-config.php points at a shared database hostname (read-only)';

    public function handle(WordPressDatabaseHostAudit $audit, WordPressDatabaseConfigAnalyzer $analyzer): int
    {
        $nodeId = $this->option('node') !== null ? (int) $this->option('node') : null;
        $total = $audit->deployments($nodeId)->count();

        if ($total === 0) {
            $this->info('No WordPress deployments to audit.');

            return self::SUCCESS;
        }

        $this->info("Auditing {$total} WordPress deployment(s). Nothing is modified.");

        $rows = [];
        $atRisk = 0;
        $unreadable = 0;

        $audit->sweep(
            $analyzer,
            $nodeId,
            function (array $row) use ($audit, &$rows, &$atRisk, &$unreadable): void {
                if ($row['verdict'] === WordPressDatabaseHostAudit::AT_RISK) {
                    $atRisk++;
                } elseif ($row['verdict'] === WordPressDatabaseHostAudit::UNREADABLE) {
                    $unreadable++;
                }

                if ($this->option('at-risk') && $row['verdict'] !== WordPressDatabaseHostAudit::AT_RISK) {
                    return;
                }

                $rows[] = [
                    $row['service_id'],
                    $row['name'],
                    $row['node'],
                    $this->describeHost($audit, $row),
                    $row['verdict'],
                ];
            },
            fn (string $hostname, string $error) => $this->warn(
                "Node {$hostname} is unreachable, so its sites are reported unreadable: {$error}"
            ),
        );

        if ($rows !== []) {
            $this->table(['Service', 'Site', 'Node', 'wp-config DB_HOST', 'Verdict'], $rows);
        }

        $this->newLine();
        $this->line('At risk: '.$atRisk);
        $this->line('Unreadable: '.$unreadable);

        if ($atRisk > 0) {
            $this->newLine();
            $this->warn('Each site above is served by whichever database Docker resolves that name to.');
            $this->line('Fix them with: php artisan containers:repair-wordpress-db-host');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function describeHost(WordPressDatabaseHostAudit $audit, array $row): string
    {
        $host = (string) $row['host'];
        if ($host === '') {
            return '—';
        }

        if ($row['verdict'] !== WordPressDatabaseHostAudit::AT_RISK) {
            return $host;
        }

        return $host.' → should be '.$audit->shouldBe($row['deployment']);
    }
}
