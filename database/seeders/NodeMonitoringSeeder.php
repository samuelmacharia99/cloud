<?php

namespace Database\Seeders;

use App\Models\Node;
use App\Models\NodeMonitoring;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class NodeMonitoringSeeder extends Seeder
{
    public function run(): void
    {
        // Get all monitorable nodes (container_host and database_server)
        $monitorableNodes = Node::whereIn('type', ['container_host', 'database_server'])->get();

        foreach ($monitorableNodes as $node) {
            // Create 288 readings (one every 5 minutes for 24 hours)
            $now = now();

            for ($i = 0; $i < 288; $i++) {
                $recordedAt = $now->copy()->subMinutes($i * 5);

                // Simulate realistic patterns
                $hour = $recordedAt->hour;

                // Peak load during business hours (9-17)
                $isPeakHour = $hour >= 9 && $hour < 17;
                $baseLoadMultiplier = $isPeakHour ? 0.7 : 0.4;

                // Add some randomness
                $variance = rand(-15, 15) / 100;

                $ramUsed = (int) ($node->ram_gb * ($baseLoadMultiplier + $variance));
                $storageUsed = (int) ($node->storage_gb * (0.5 + rand(-10, 10) / 100));
                $uptime = max(90, min(100, 98 + rand(-8, 5)));

                NodeMonitoring::create([
                    'node_id' => $node->id,
                    'uptime_percentage' => $uptime,
                    'ram_used_gb' => max(0, $ramUsed),
                    'ram_total_gb' => $node->ram_gb,
                    'storage_used_gb' => max(0, $storageUsed),
                    'storage_total_gb' => $node->storage_gb,
                    'cpu_percentage' => (int) ($baseLoadMultiplier * 100 + rand(-20, 20)),
                    'recorded_at' => $recordedAt,
                ]);
            }
        }
    }
}
