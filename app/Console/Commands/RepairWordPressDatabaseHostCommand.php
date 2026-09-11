<?php

namespace App\Console\Commands;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerDoctorService;
use App\Services\Provisioning\WordPressDatabaseConfigAnalyzer;
use App\Services\Provisioning\WordPressDatabaseHostAudit;
use App\Services\SSH\SSHService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Pin every WordPress site to its own database instead of a shared name.
 *
 * See WordPressDatabaseHostAudit for the defect. The repair itself is the one
 * Doctor already runs from the panel, applied to a list rather than to a single
 * service, because twenty-four sites is too many to click through and the sites
 * carrying it are failing intermittently in the meantime.
 *
 * Two things this deliberately does not do. It does not run repairs in
 * parallel: each one recreates a container, and a node recreating a dozen at
 * once is its own outage. And it does not trust the repair's own verdict:
 * Doctor logs a failed wp-config rewrite as a warning and still reports
 * success, so every site is re-read afterwards and only a changed file counts.
 */
class RepairWordPressDatabaseHostCommand extends Command
{
    protected $signature = 'containers:repair-wordpress-db-host
        {--node= : Only repair services on this node id}
        {--service=* : Only repair these service ids}
        {--limit= : Stop after this many repairs}
        {--dry-run : List what would be repaired and exit}
        {--continue-on-failure : Keep going after a site fails}
        {--force : Do not ask for confirmation}';

    protected $description = 'Point WordPress sites at their own database sidecar instead of the shared hostname';

    public function handle(
        WordPressDatabaseHostAudit $audit,
        WordPressDatabaseConfigAnalyzer $analyzer,
        ContainerDoctorService $doctor,
    ): int {
        $targets = $this->collectTargets($audit, $analyzer);

        if ($targets === []) {
            $this->info('No WordPress site is pointing at a shared database hostname.');

            return self::SUCCESS;
        }

        $this->table(
            ['Service', 'Site', 'Node', 'wp-config DB_HOST', 'Will become'],
            array_map(static fn (array $row): array => [
                $row['service_id'],
                $row['name'],
                $row['node'],
                $row['host'],
                $row['should_be'],
            ], $targets),
        );

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info(count($targets).' site(s) would be repaired. Nothing was modified.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Each repair rewrites wp-config.php and recreates the application container.');
        $this->line('The database volume is kept. Expect a few seconds of downtime per site.');

        if (! $this->option('force') && ! $this->confirm('Repair '.count($targets).' site(s) now?', false)) {
            $this->info('Nothing was modified.');

            return self::SUCCESS;
        }

        return $this->repairAll($audit, $analyzer, $doctor, $targets);
    }

    /**
     * @param  list<array<string, mixed>>  $targets
     */
    private function repairAll(
        WordPressDatabaseHostAudit $audit,
        WordPressDatabaseConfigAnalyzer $analyzer,
        ContainerDoctorService $doctor,
        array $targets,
    ): int {
        $repaired = 0;
        $failed = [];

        foreach ($targets as $index => $target) {
            $position = ($index + 1).'/'.count($targets);
            $this->line("[{$position}] Service {$target['service_id']} {$target['name']}");

            $outcome = $this->repairOne($audit, $analyzer, $doctor, $target);

            if ($outcome['ok']) {
                $repaired++;
                $this->info('      pinned to '.$target['should_be']);

                continue;
            }

            $failed[] = $target['service_id'];
            $this->error('      '.$outcome['message']);

            if (! $this->option('continue-on-failure')) {
                $this->newLine();
                $this->error('Stopping here rather than marching through the rest of the fleet.');
                $this->line('Re-run with --continue-on-failure once you know why this one failed.');

                break;
            }
        }

        $this->newLine();
        $this->line('Repaired: '.$repaired);
        $this->line('Failed: '.count($failed));

        if ($failed !== []) {
            $this->line('Still on a shared hostname: service '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array{ok: bool, message: string}
     */
    private function repairOne(
        WordPressDatabaseHostAudit $audit,
        WordPressDatabaseConfigAnalyzer $analyzer,
        ContainerDoctorService $doctor,
        array $target,
    ): array {
        $deployment = $target['deployment'];
        $service = $deployment->service;

        try {
            $result = $doctor->treat($service, 'sync_database_credentials');
        } catch (\Throwable $e) {
            return $this->record($target, false, 'repair threw: '.mb_substr(trim($e->getMessage()), 0, 200));
        }

        if (! ($result['success'] ?? false)) {
            return $this->record($target, false, $this->summarize($result));
        }

        // Success is still not enough. Doctor logs a failed wp-config rewrite
        // as a warning and returns success anyway, and wp-config is the only
        // file that decides where WordPress dials.
        $verified = $this->verifyPinned($audit, $analyzer, $deployment);

        if ($verified === null) {
            return $this->record($target, false, 'repair reported "'.$this->summarize($result).'" but wp-config.php could not be re-read');
        }

        if ($verified !== true) {
            return $this->record($target, false, 'wp-config.php still points at a shared hostname after the repair: '.$this->summarize($result));
        }

        return $this->record($target, true, (string) ($result['message'] ?? 'repaired'));
    }

    /**
     * True when wp-config now names this stack's own database, false when it
     * still names a shared one, null when the file could not be read at all.
     */
    private function verifyPinned(
        WordPressDatabaseHostAudit $audit,
        WordPressDatabaseConfigAnalyzer $analyzer,
        ContainerDeployment $deployment,
    ): ?bool {
        $deployment = $deployment->fresh(['node', 'service']);
        if (! $deployment || ! $deployment->node) {
            return null;
        }

        $ssh = SSHService::forNode($deployment->node);

        try {
            $row = $audit->inspect($analyzer, $ssh, $deployment, (string) $deployment->node->hostname);
        } catch (\Throwable) {
            return null;
        } finally {
            $ssh->disconnect();
        }

        return match ($row['verdict']) {
            WordPressDatabaseHostAudit::PINNED => true,
            WordPressDatabaseHostAudit::AT_RISK => false,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $target
     * @return array{ok: bool, message: string}
     */
    private function record(array $target, bool $ok, string $message): array
    {
        Log::info('Bulk WordPress database host repair', [
            'service_id' => $target['service_id'],
            'was' => $target['host'],
            'should_be' => $target['should_be'],
            'ok' => $ok,
            'message' => $message,
        ]);

        return ['ok' => $ok, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function summarize(array $result): string
    {
        return mb_substr(trim((string) ($result['message'] ?? 'no message')), 0, 200);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectTargets(
        WordPressDatabaseHostAudit $audit,
        WordPressDatabaseConfigAnalyzer $analyzer,
    ): array {
        $nodeId = $this->option('node') !== null ? (int) $this->option('node') : null;
        $only = array_map('intval', (array) $this->option('service'));
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $this->info('Reading wp-config.php on every WordPress site. Nothing is modified yet.');

        $targets = [];

        $audit->sweep(
            $analyzer,
            $nodeId,
            function (array $row) use ($audit, $only, $limit, &$targets): void {
                if ($limit !== null && count($targets) >= $limit) {
                    return;
                }

                if ($row['verdict'] !== WordPressDatabaseHostAudit::AT_RISK) {
                    return;
                }

                if ($only !== [] && ! in_array((int) $row['service_id'], $only, true)) {
                    return;
                }

                $row['should_be'] = $audit->shouldBe($row['deployment']);
                $targets[] = $row;
            },
            fn (string $hostname, string $error) => $this->warn(
                "Node {$hostname} is unreachable, so its sites were skipped: {$error}"
            ),
        );

        return $targets;
    }
}
