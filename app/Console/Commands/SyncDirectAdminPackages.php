<?php

namespace App\Console\Commands;

use App\Models\Node;
use App\Services\Provisioning\DirectAdminService;
use Illuminate\Console\Command;

class SyncDirectAdminPackages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'directadmin:sync-packages {--node=* : Sync packages from specific node(s) (use --node=ID --node=ID2)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync DirectAdmin packages from connected server(s) into the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting DirectAdmin package synchronization...');

        $nodeIds = $this->option('node');
        $query = Node::where('type', 'directadmin')->where('is_active', true);

        if (!empty($nodeIds)) {
            $query->whereIn('id', $nodeIds);
        }

        $nodes = $query->get();

        if ($nodes->isEmpty()) {
            $this->warn('No active DirectAdmin nodes found.');
            return self::SUCCESS;
        }

        $totalSynced = 0;
        $totalUpdated = 0;
        $totalFailed = 0;

        foreach ($nodes as $node) {
            $this->line("\n<fg=cyan>Syncing packages from: {$node->name}</>");

            try {
                $service = new DirectAdminService($node);

                if (!$service->isConfigured()) {
                    $this->error("  ❌ DirectAdmin not configured for node: {$node->name}");
                    $totalFailed++;
                    continue;
                }

                $result = $service->syncPackages();

                $totalSynced += $result['synced'];
                $totalUpdated += $result['updated'];
                $totalFailed += $result['failed'];

                if ($result['synced'] > 0 || $result['updated'] > 0) {
                    $this->info("  ✓ Synced: {$result['synced']}, Updated: {$result['updated']}");
                }

                if ($result['failed'] > 0) {
                    $this->warn("  ⚠ Failed: {$result['failed']}");
                    foreach ($result['errors'] as $error) {
                        $this->line("    - $error");
                    }
                }
            } catch (\Exception $e) {
                $this->error("  ❌ Error syncing from {$node->name}: {$e->getMessage()}");
                $totalFailed++;
            }
        }

        $this->newLine();
        $this->info('=== Synchronization Summary ===');
        $this->line("<info>Total Created:</info> <fg=green>$totalSynced</>");
        $this->line("<info>Total Updated:</info> <fg=cyan>$totalUpdated</>");
        $this->line("<info>Total Failed:</info> <fg=red>$totalFailed</>");

        return self::SUCCESS;
    }
}
