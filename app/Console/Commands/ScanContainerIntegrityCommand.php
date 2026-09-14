<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramMonitorAlertJob;
use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerIncidentService;
use App\Services\Provisioning\ContainerIntegrityScanner;
use App\Services\Provisioning\WordPressSecurityBaseline;
use App\Services\SSH\SSHService;
use Illuminate\Support\Facades\Log;

/**
 * Nightly integrity pass over WordPress and PHP stacks. Files that cannot be
 * legitimate are archived into an incident and removed; anything that merely
 * looks suspicious is recorded and raised to the operators.
 */
class ScanContainerIntegrityCommand extends BaseCronCommand
{
    protected $signature = 'cron:scan-container-integrity {--service= : Only this service id} {--no-quarantine : Report only, never remove}';

    protected $description = 'Scan running WordPress and PHP containers for webshells, foreign files and modified core; quarantine what cannot be legitimate';

    public function handleCron(): string
    {
        $scanner = app(ContainerIntegrityScanner::class);
        $incidents = app(ContainerIncidentService::class);
        $autoQuarantine = ! $this->option('no-quarantine') && (bool) config('containers.integrity.nightly_auto_quarantine', true);

        $query = ContainerDeployment::query()
            ->whereIn('status', ['running', 'active'])
            ->with(['node', 'service.product.containerTemplate'])
            ->orderBy('id');
        if ($this->option('service')) {
            $query->where('service_id', (int) $this->option('service'));
        }

        $scanned = 0;
        $flagged = 0;
        $quarantined = 0;
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
                $scanned++;

                $moved = ['moved' => [], 'incident' => null, 'bytes' => 0, 'skipped' => [], 'quarantine_dir' => ''];
                $keysRotated = false;
                if ($autoQuarantine) {
                    $moved = $scanner->quarantine(
                        $ssh,
                        $service,
                        $deployment,
                        $result['hits'],
                        ContainerIntegrityScanner::AUTO_QUARANTINE_REASONS,
                        ContainerIncidentService::TRIGGER_NIGHTLY,
                    );
                    if ($moved['moved'] !== []) {
                        $quarantined++;
                        $movedHits = array_filter($result['hits'], fn ($h) => in_array($h['path'], $moved['moved'], true));
                        $webshell = array_filter($movedHits, fn ($h) => array_intersect($h['reasons'], ContainerIntegrityScanner::WEBSHELL_REASONS) !== []);
                        if ($wordpress && $webshell !== []) {
                            $keysRotated = app(WordPressSecurityBaseline::class)->rotateSalts($ssh, $deployment)['success'];
                        }
                    }
                }

                $stored = $scanner->persist($service, $result, $moved['moved']);
                $incidents->prune($ssh, $deployment);
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('Integrity scan failed', ['service_id' => $service->id, 'error' => $e->getMessage()]);

                continue;
            } finally {
                $ssh->disconnect();
            }

            if ($stored['suspicious'] > 0) {
                $flagged++;
            }

            if (! (bool) config('containers.integrity.alert', true)) {
                continue;
            }
            if ($moved['moved'] !== []) {
                $incidents->alert(
                    $service,
                    $deployment,
                    (string) $moved['incident'],
                    ContainerIncidentService::TRIGGER_NIGHTLY,
                    $moved['moved'],
                    ($keysRotated ? 'Security keys were rotated. ' : '').($stored['suspicious'] > 0 ? $stored['suspicious'].' more file(s) wait for a Doctor decision.' : ''),
                );
                $alerted++;
            } elseif ($stored['new_paths'] !== []) {
                SendTelegramMonitorAlertJob::dispatch(
                    'security',
                    'Suspicious files on '.($deployment->probeHostHeader() ?: $deployment->container_name),
                    [
                        'service' => '#'.$service->id.' '.$service->name,
                        'new files' => count($stored['new_paths']),
                        'total flagged' => $stored['suspicious'],
                        'first' => implode(', ', array_slice($stored['new_paths'], 0, 5)),
                    ],
                    'Open the service, run Container Doctor and quarantine from there.',
                );
                $alerted++;
            }
        }

        return sprintf(
            'Scanned %d stack(s): %d with suspicious files, %d quarantined, %d alerts, %d scan failures.',
            $scanned,
            $flagged,
            $quarantined,
            $alerted,
            $failed
        );
    }
}
