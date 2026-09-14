<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramMonitorAlertJob;
use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerIntegrityScanner;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Nightly integrity pass over WordPress and PHP stacks: records what the
 * scanner found on each service and raises an operator alert when a stack
 * gains new suspicious files since the last pass.
 */
class ScanContainerIntegrityCommand extends BaseCronCommand
{
    protected $signature = 'cron:scan-container-integrity {--service= : Only this service id}';

    protected $description = 'Scan running WordPress and PHP containers for webshells, foreign files and modified core';

    public function handleCron(): string
    {
        $scanner = app(ContainerIntegrityScanner::class);
        $query = ContainerDeployment::query()
            ->whereIn('status', ['running', 'active'])
            ->with(['node', 'service.product.containerTemplate'])
            ->orderBy('id');
        if ($this->option('service')) {
            $query->where('service_id', (int) $this->option('service'));
        }

        $scanned = 0;
        $flagged = 0;
        $alerted = 0;
        $failed = 0;

        foreach ($query->cursor() as $deployment) {
            $service = $deployment->service;
            $slug = (string) ($service?->product?->containerTemplate?->slug ?? '');
            if (! $service || ! $deployment->node) {
                continue;
            }
            $wordpress = $slug === 'wordpress' || str_ends_with((string) $deployment->container_name, '-wordpress');
            if (! $wordpress && ! in_array($slug, ['php', 'laravel'], true)) {
                continue;
            }

            $ssh = SSHService::forNode($deployment->node);
            try {
                $result = $scanner->scan($ssh, $deployment, $wordpress, withChecksums: $wordpress);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Integrity scan failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);

                continue;
            } finally {
                $ssh->disconnect();
            }
            $scanned++;

            $stored = $scanner->persist($service, $result);
            $newPaths = $stored['new_paths'];
            if ($stored['suspicious'] > 0) {
                $flagged++;
            }
            if ($newPaths !== [] && (bool) config('containers.integrity.alert', true)) {
                SendTelegramMonitorAlertJob::dispatch(
                    'security',
                    'Suspicious files on '.($deployment->probeHostHeader() ?: $deployment->container_name),
                    [
                        'service' => '#'.$service->id.' '.$service->name,
                        'new files' => count($newPaths),
                        'total flagged' => $stored['suspicious'],
                        'first' => implode(', ', array_slice($newPaths, 0, 5)),
                    ],
                    'Open the service, run Container Doctor and quarantine from there.',
                );
                $alerted++;
            }
        }

        return sprintf('Scanned %d stack(s): %d with suspicious files, %d new alerts, %d scan failures.', $scanned, $flagged, $alerted, $failed);
    }
}
