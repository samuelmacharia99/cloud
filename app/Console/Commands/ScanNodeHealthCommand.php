<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\Provisioning\NodeDoctorService;
use App\Services\Provisioning\NodeIncidentRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Ask every shared server what is wrong with it, on a schedule.
 *
 * The hardware poll that runs beside this one has read six numbers every two
 * minutes for months, none of which change when the web server dies. This is
 * the one that notices.
 */
class ScanNodeHealthCommand extends BaseCronCommand
{
    protected $signature = 'cron:scan-node-health
        {--node= : Only scan this node id}';

    protected $description = 'Check DirectAdmin node services, disks and queues, and alert on what changed';

    protected function handleCron(): string
    {
        $doctor = app(NodeDoctorService::class);
        $incidents = app(NodeIncidentRecorder::class);

        $nodes = Node::query()
            ->where('is_active', true)
            ->where('type', 'directadmin')
            ->when($this->option('node'), fn ($query) => $query->whereKey((int) $this->option('node')))
            ->get();

        if ($nodes->isEmpty()) {
            return 'No active DirectAdmin nodes to scan.';
        }

        $critical = 0;
        $opened = 0;
        $unreachable = 0;

        foreach ($nodes as $node) {
            try {
                $diagnosis = $doctor->diagnose($node);
            } catch (\Throwable $e) {
                // One unreachable node must not end the sweep for the rest.
                $unreachable++;
                Log::warning('Node health scan failed', [
                    'node_id' => $node->id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $diagnosis['reachable']) {
                $unreachable++;
            }

            $changes = $incidents->reconcile($node, $diagnosis);
            $opened += count($changes['opened']);

            $critical += count(array_filter(
                $diagnosis['findings'],
                fn (array $finding): bool => ($finding['severity'] ?? '') === 'critical',
            ));
        }

        return sprintf(
            'Scanned %d DirectAdmin node(s): %d critical finding(s), %d newly opened, %d unreachable.',
            $nodes->count(),
            $critical,
            $opened,
            $unreachable,
        );
    }
}
