<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\NotificationService;
use App\Services\Provisioning\ContainerNodeCapacityService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckContainerNodeCapacityCommand extends BaseCronCommand
{
    protected $signature = 'cron:check-container-node-capacity';

    protected $description = 'Alerts admins to provision another application host when capacity reaches ~70%';

    public function __construct(
        private ContainerNodeCapacityService $capacity,
    ) {
        parent::__construct();
    }

    protected function handleCron(): string
    {
        $threshold = max(1, min(99, (int) config(
            'containers.elastic_resources.scale_out_threshold_percent',
            70
        )));
        $cooldownMinutes = max(15, (int) config(
            'containers.elastic_resources.scale_out_alert_cooldown_minutes',
            360
        ));

        $nodes = Node::query()
            ->where('type', 'container_host')
            ->where('is_active', true)
            ->whereIn('status', ['online', 'degraded'])
            ->orderBy('name')
            ->get();

        if ($nodes->isEmpty()) {
            return 'No active application hosts to evaluate.';
        }

        $pressured = [];
        $alerted = 0;

        foreach ($nodes as $node) {
            $evaluation = $this->capacity->evaluate($node);
            if ($evaluation['pressure_percent'] < $threshold) {
                continue;
            }

            $pressured[] = [
                'node' => $node,
                'evaluation' => $evaluation,
            ];

            $cacheKey = 'container-node-scale-out:'.$node->id;
            if (! Cache::add($cacheKey, true, now()->addMinutes($cooldownMinutes))) {
                continue;
            }

            app(NotificationService::class)->notifyAdminNodeScaleOutNeeded(
                $node,
                $evaluation,
                $threshold
            );
            $alerted++;

            Log::warning('Application host capacity requires scale-out', [
                'node_id' => $node->id,
                'hostname' => $node->hostname,
                'pressure_percent' => $evaluation['pressure_percent'],
                'threshold_percent' => $threshold,
                'drivers' => $evaluation['drivers'],
            ]);
        }

        $onlineCount = $nodes->where('status', 'online')->count();
        $pressuredCount = count($pressured);

        if ($pressuredCount > 0 && $pressuredCount === $nodes->count()) {
            $fleetKey = 'container-node-scale-out:fleet';
            if (Cache::add($fleetKey, true, now()->addMinutes($cooldownMinutes))) {
                app(NotificationService::class)->notifyAdminFleetScaleOutNeeded(
                    $pressuredCount,
                    $threshold
                );
                $alerted++;
            }
        }

        return sprintf(
            'Checked %d application host(s). %d at/above %d%% pressure. %d alert(s) sent. %d online.',
            $nodes->count(),
            $pressuredCount,
            $threshold,
            $alerted,
            $onlineCount
        );
    }
}
