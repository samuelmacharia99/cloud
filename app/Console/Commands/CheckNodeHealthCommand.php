<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Models\ContainerDeployment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\GenericNotification;

class CheckNodeHealthCommand extends BaseCronCommand
{
    protected $signature = 'cron:check-node-health';
    protected $description = 'Sets monitored nodes to offline/degraded based on last heartbeat';

    protected function handleCron(): string
    {
        $lines = [];
        $nodes = Node::where('is_active', true)
            ->whereIn('type', ['container_host', 'database_server'])
            ->get();

        $offline = 0;
        $degraded = 0;

        foreach ($nodes as $node) {
            $heartbeat = $node->last_heartbeat_at;

            if (is_null($heartbeat) || $heartbeat->lt(now()->subMinutes(15))) {
                if ($node->status !== 'offline') {
                    $node->update(['status' => 'offline']);
                    Log::error("NODE OFFLINE: {$node->name} ({$node->ip_address}) — last heartbeat: " . ($heartbeat?->toDateTimeString() ?? 'never'));

                    // Notify admin if container node goes offline
                    if ($node->type === 'container_host') {
                        $this->notifyOfflineContainerNode($node);
                    }
                }
                $offline++;
            } elseif ($heartbeat->lt(now()->subMinutes(5))) {
                if ($node->status !== 'degraded') {
                    $node->update(['status' => 'degraded']);
                    Log::warning("NODE DEGRADED: {$node->name} ({$node->ip_address}) — heartbeat " . $heartbeat->diffForHumans());
                }
                $degraded++;
            } else {
                // Heartbeat is fresh (<5 min) — restore online if was degraded/offline
                if (in_array($node->status, ['offline', 'degraded'])) {
                    $node->update(['status' => 'online']);
                }
            }
        }

        $lines[] = "Checked {$nodes->count()} monitored node(s).";
        if ($offline) {
            $lines[] = "{$offline} offline.";
        }
        if ($degraded) {
            $lines[] = "{$degraded} degraded.";
        }

        return implode(' | ', $lines);
    }

    /**
     * Send notification to admin when container node goes offline
     */
    private function notifyOfflineContainerNode(Node $node): void
    {
        try {
            // Get all active deployments on this node
            $deployments = ContainerDeployment::where('node_id', $node->id)
                ->whereIn('status', ['running', 'stopped', 'deploying'])
                ->with('service.user')
                ->get();

            if ($deployments->isEmpty()) {
                return;
            }

            $adminEmail = setting('admin_email', config('mail.from.address'));
            $affectedCount = $deployments->count();

            // Compose service list
            $servicesList = $deployments->map(function ($deployment) {
                return "- {$deployment->service->name} (Customer: {$deployment->service->user->name})";
            })->join("\n");

            $subject = "URGENT: Container Node {$node->hostname} Is Offline";
            $body = <<<EOT
A container hosting node has gone offline and requires immediate attention.

**Node Details:**
- Hostname: {$node->hostname}
- IP Address: {$node->ip_address}
- Status: Offline (no heartbeat for 15+ minutes)

**Affected Services:** {$affectedCount} container(s) are deployed on this node

$servicesList

**Required Action:**
1. Investigate the node connectivity issue
2. If recovery is not possible within a reasonable timeframe, migrate affected containers to another node
3. Use the admin dashboard to monitor migration progress

**Migration Instructions:**
Visit the service details page and use the "Migrate Container" option to move services to another available node.

This is an automated alert from Talksasa Cloud.
EOT;

            // Send email to admin
            Mail::to($adminEmail)->send(new GenericNotification(
                subject: $subject,
                heading: 'Container Node Offline Alert',
                body: $body
            ));

            Log::warning("Offline container node notification sent for {$node->hostname}");
        } catch (\Exception $e) {
            Log::error("Failed to send offline node notification: " . $e->getMessage());
        }
    }
}
