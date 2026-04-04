<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Support\Facades\Log;

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
}
