<?php

namespace App\Services\Provisioning;

use App\Models\Service;
use Illuminate\Support\Facades\Cache;

/**
 * Live operator view of DirectAdmin → Mailcow mail copy (percent, phase, log).
 */
class DirectAdminMailPullProgress
{
    public const STATUSES_ACTIVE = ['pending', 'running'];

    private const CACHE_TTL_SECONDS = 21600;

    private const LOG_MAX_BYTES = 120000;

    private const META_LOG_MAX_BYTES = 24000;

    /** @var array<int, float> */
    private array $lastMetaWriteAt = [];

    /** @var array<int, int> */
    private array $lastLoggedBucket = [];

    public function cacheKey(Service|int $service): string
    {
        $id = $service instanceof Service ? $service->id : $service;

        return 'da_mail_pull.'.$id;
    }

    public function isActive(Service $service): bool
    {
        return in_array($this->snapshot($service)['status'] ?? '', self::STATUSES_ACTIVE, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function queue(Service $service): array
    {
        $previous = $this->snapshot($service);
        $state = $this->blank($service);
        $state['run'] = (int) ($previous['run'] ?? 0);
        $state['status'] = 'pending';
        $state['percent'] = 1;
        $state['label'] = 'Queued mail pull…';
        $state['phase'] = 'queued';
        $state['started_at'] = now()->toIso8601String();
        $state['log'] = $this->line('Queued mail pull from DirectAdmin. Waiting for a worker…');
        $this->persist($service, $state, true);

        return $state;
    }

    /**
     * Start a run. The log is reset so the terminal shows this run only; the
     * previous run's outcome survives as the first line for context.
     */
    public function begin(Service $service, int $mailboxTotal): array
    {
        $previous = $this->snapshot($service);
        $state = $this->blank($service);
        $state['run'] = (int) ($previous['run'] ?? 0) + 1;
        $state['status'] = 'running';
        $state['mailbox_total'] = max(0, $mailboxTotal);
        $state['mailbox_index'] = 0;
        $state['phase'] = 'prepare';
        $state['label'] = $mailboxTotal > 0
            ? 'Preparing '.$mailboxTotal.' mailbox(es)…'
            : 'No mailboxes to copy';
        $state['percent'] = 2;
        $state['started_at'] = now()->toIso8601String();
        $state['error'] = null;
        $state['copied'] = [];
        $state['sync_jobs'] = [];
        $state['failed'] = [];
        if ($state['run'] > 1) {
            $previousOutcome = trim((string) ($previous['label'] ?? ''));
            $this->appendLog($state, sprintf(
                '── mail pull run %d ── previous run %s%s',
                $state['run'],
                (string) ($previous['status'] ?? 'unknown'),
                $previousOutcome !== '' ? ': '.$previousOutcome : '',
            ));
        }
        $this->appendLog($state, 'Pulling '.$mailboxTotal.' mailbox(es) from DirectAdmin to Mailcow');
        $state['percent'] = $this->computePercent($state);
        $this->persist($service, $state, true);

        return $state;
    }

    public function mailbox(Service $service, int $index, int $total, string $email, int $bytes = 0): void
    {
        $state = $this->snapshot($service);
        $state['status'] = 'running';
        $state['mailbox_index'] = $index;
        $state['mailbox_total'] = max($total, $index);
        $state['current_email'] = $email;
        $state['phase'] = 'mailbox';
        $state['bytes_done'] = 0;
        $state['bytes_total'] = max(0, $bytes);
        $state['phase_fraction'] = 0.0;
        $size = $bytes > 0 ? ' · '.self::formatBytes($bytes) : '';
        $state['label'] = $email.' ('.$index.'/'.$state['mailbox_total'].')'.$size;
        $this->appendLog($state, $email.' ('.$index.'/'.$state['mailbox_total'].')'.$size);
        $state['percent'] = $this->computePercent($state);
        $this->persist($service, $state, true);
        $this->lastLoggedBucket[$service->id] = -1;
    }

    public function phase(Service $service, string $phase, string $detail = '', int $bytesDone = 0, int $bytesTotal = 0): void
    {
        $state = $this->snapshot($service);
        $state['status'] = 'running';
        $state['phase'] = $phase;
        $state['bytes_done'] = max(0, $bytesDone);
        if ($bytesTotal > 0) {
            $state['bytes_total'] = $bytesTotal;
        }
        $state['phase_fraction'] = $this->phaseFraction($phase, $bytesDone, (int) ($state['bytes_total'] ?? 0));
        $state['label'] = $this->buildLabel($state, $detail);
        $state['percent'] = $this->computePercent($state);

        $bucket = $bytesTotal > 0 ? (int) floor(($bytesDone / $bytesTotal) * 10) : -1;
        $shouldLog = ! in_array($phase, ['download', 'upload'], true)
            || $bucket !== ($this->lastLoggedBucket[$service->id] ?? -1);
        if ($shouldLog && $detail !== '') {
            $this->appendLog($state, '  '.$detail);
            $this->lastLoggedBucket[$service->id] = $bucket;
        }

        $force = ! in_array($phase, ['download', 'upload'], true);
        $this->persist($service, $state, $force);
    }

    public function log(Service $service, string $line): void
    {
        $state = $this->snapshot($service);
        $this->appendLog($state, $line);
        $this->persist($service, $state, true);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function complete(Service $service, string $message, array $extra = []): array
    {
        $state = $this->snapshot($service);
        $state['status'] = 'completed';
        $state['percent'] = 100;
        $state['phase'] = 'done';
        $state['phase_fraction'] = 1.0;
        $state['label'] = $message;
        $state['error'] = null;
        $state['completed_at'] = now()->toIso8601String();
        if (isset($extra['copied_maildirs']) && is_array($extra['copied_maildirs'])) {
            $state['copied'] = array_values($extra['copied_maildirs']);
        }
        if (isset($extra['sync_jobs']) && is_array($extra['sync_jobs'])) {
            $state['sync_jobs'] = array_values($extra['sync_jobs']);
        }
        if (isset($extra['failed_mailboxes']) && is_array($extra['failed_mailboxes'])) {
            $state['failed'] = array_values($extra['failed_mailboxes']);
        }
        $this->appendLog($state, $message);
        $this->persist($service, $state, true);

        return $state;
    }

    public function fail(Service $service, string $message): array
    {
        $state = $this->snapshot($service);
        $state['status'] = 'failed';
        $state['phase'] = 'failed';
        $state['label'] = $message;
        $state['error'] = $message;
        $state['completed_at'] = now()->toIso8601String();
        $state['percent'] = max(8, (int) ($state['percent'] ?? 0));
        $this->appendLog($state, 'FAILED: '.$message);
        $this->persist($service, $state, true);

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(Service $service): array
    {
        $cached = Cache::get($this->cacheKey($service));
        if (is_array($cached) && ($cached['status'] ?? '') !== '') {
            return $this->normalize($cached);
        }

        $meta = is_array($service->service_meta['mail_pull'] ?? null)
            ? $service->service_meta['mail_pull']
            : [];

        return $this->normalize($meta === [] ? $this->blank($service) : $meta);
    }

    /**
     * Combined convert + mail-pull payload for the admin operator console.
     *
     * The convert half (phases, siblings, deploy lines, retry) comes from
     * DaConvertProgress; this method blends it with the live mail copy.
     *
     * @return array<string, mixed>
     */
    public function operatorView(Service $service): array
    {
        $pull = $this->snapshot($service);
        $view = app(DaConvertProgress::class)->operatorConvertView($service, $pull);
        $convert = $view['convert'];
        $pullActive = in_array($pull['status'] ?? '', self::STATUSES_ACTIVE, true);
        $convertStatus = (string) $view['convert_status'];
        $convertActive = (bool) $view['convert_active'];
        $siblingsActive = (bool) $view['siblings_active'];
        $pullStatus = (string) ($pull['status'] ?? 'idle');
        $mailPhaseRunning = $convertActive && $view['convert_phase'] === 'mail';

        // Percent: the convert bar owns the page once a convert exists; the
        // mail pull's own percent fills the mail phase span inside it.
        if ($pullActive && ! $mailPhaseRunning) {
            // Standalone mail-pull retry: the copy owns the bar until it ends.
            $percent = (int) $pull['percent'];
            $status = $pullStatus;
        } elseif ($convert !== null) {
            $percent = (int) $view['convert_percent'];
            $status = $convertStatus;
            if ($convertStatus === 'completed' && $siblingsActive) {
                $percent = 99;
                $status = 'running';
            }
        } elseif ($pullStatus === 'completed') {
            $percent = 100;
            $status = $pullStatus;
        } elseif ($pullStatus === 'failed') {
            $percent = (int) ($pull['percent'] ?? 0);
            $status = 'failed';
        } else {
            $percent = (int) ($pull['percent'] ?? 0);
            $status = $pullStatus !== 'idle' ? $pullStatus : 'idle';
        }

        $label = '';
        if ($mailPhaseRunning || ($convert === null && $pullStatus !== 'idle') || ($pullActive && ! $mailPhaseRunning)) {
            $label = (string) ($pull['label'] ?? '');
        }
        if ($label === '') {
            $label = (string) $view['convert_label'];
        }
        if ($label === '' && $pullStatus === 'failed') {
            $label = (string) ($pull['label'] ?? '');
        }

        $sections = [];
        $steps = $view['convert_steps'];
        if ($steps !== []) {
            $sections[] = implode("\n", array_map(
                fn ($step) => '['.($convertStatus ?: 'convert').'] '.$step,
                $steps
            ));
        }
        $deployLines = $view['deploy_lines'];
        if ($deployLines !== []) {
            $sections[] = "── container deploy ──\n".implode("\n", $deployLines);
        }
        $pullLog = (string) ($pull['log'] ?? '');
        if ($pullLog !== '') {
            $sections[] = ($steps !== [] ? "── mail pull ──\n" : '').$pullLog;
        }
        $log = implode("\n", $sections);

        $legacy = is_array($service->service_meta['da_legacy'] ?? null) ? $service->service_meta['da_legacy'] : [];
        $migration = is_array($service->service_meta['mailcow_migration'] ?? null) ? $service->service_meta['mailcow_migration'] : [];
        $canRetry = (! $pullActive)
            && (! $convertActive)
            && (
                (int) ($legacy['email_service_id'] ?? 0) > 0
                || (int) ($migration['email_service_id'] ?? 0) > 0
            );

        $isActive = $pullActive || $convertActive || $siblingsActive;

        return array_merge($view, [
            'percent' => max(0, min(100, $percent)),
            'label' => $label !== '' ? $label : 'Idle',
            'log' => $log !== '' ? $log : 'Awaiting mail pull…',
            'is_active' => $isActive,
            'status' => $status,
            'can_retry' => $canRetry,
            'can_retry_convert' => $view['can_retry_convert'] && ! $pullActive,
            'mail_pull' => $pull,
            'current_email' => $pull['current_email'] ?? null,
            'mailbox_index' => (int) ($pull['mailbox_index'] ?? 0),
            'mailbox_total' => (int) ($pull['mailbox_total'] ?? 0),
            'bytes_done' => (int) ($pull['bytes_done'] ?? 0),
            'bytes_total' => (int) ($pull['bytes_total'] ?? 0),
            'phase' => $mailPhaseRunning || ($convert === null && $pullStatus !== 'idle') || ($pullActive && ! $mailPhaseRunning)
                ? ($pull['phase'] ?? 'idle')
                : ($convertActive ? (string) $view['convert_phase'] : ($siblingsActive ? 'siblings' : 'idle')),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public function computePercent(array $state): int
    {
        if (($state['status'] ?? '') === 'completed') {
            return 100;
        }

        if (($state['status'] ?? '') === 'pending') {
            return max(1, min(4, (int) ($state['percent'] ?? 1)));
        }

        $total = max(1, (int) ($state['mailbox_total'] ?? 0));
        $index = max(0, (int) ($state['mailbox_index'] ?? 0));
        $finished = max(0, $index - 1);
        $fraction = (float) ($state['phase_fraction'] ?? 0);
        if ($index === 0) {
            return min(99, max(2, (int) round($fraction * 8)));
        }

        $pct = (($finished + min(1, max(0, $fraction))) / $total) * 100;

        if (($state['status'] ?? '') === 'failed') {
            return max(8, min(99, (int) round($pct)));
        }

        return min(99, max(3, (int) round($pct)));
    }

    public static function formatBytes(int $bytes): string
    {
        $bytes = max(0, $bytes);
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($value >= 10 ? number_format($value, 0) : number_format($value, 1)).' '.$units[$unit];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(Service $service): array
    {
        return $this->normalize([
            'service_id' => $service->id,
            'status' => 'idle',
            'percent' => 0,
            'label' => 'Idle',
            'phase' => 'idle',
            'log' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function normalize(array $state): array
    {
        return [
            'service_id' => (int) ($state['service_id'] ?? 0),
            'status' => (string) ($state['status'] ?? 'idle'),
            'percent' => (int) ($state['percent'] ?? 0),
            'label' => (string) ($state['label'] ?? ''),
            'phase' => (string) ($state['phase'] ?? 'idle'),
            'phase_fraction' => (float) ($state['phase_fraction'] ?? 0),
            'current_email' => $state['current_email'] ?? null,
            'mailbox_index' => (int) ($state['mailbox_index'] ?? 0),
            'mailbox_total' => (int) ($state['mailbox_total'] ?? 0),
            'bytes_done' => (int) ($state['bytes_done'] ?? 0),
            'bytes_total' => (int) ($state['bytes_total'] ?? 0),
            'copied' => is_array($state['copied'] ?? null) ? $state['copied'] : [],
            'sync_jobs' => is_array($state['sync_jobs'] ?? null) ? $state['sync_jobs'] : [],
            'failed' => is_array($state['failed'] ?? null) ? $state['failed'] : [],
            'log' => (string) ($state['log'] ?? ''),
            'run' => (int) ($state['run'] ?? 0),
            'error' => $state['error'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'completed_at' => $state['completed_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    private function phaseFraction(string $phase, int $done, int $total): float
    {
        $ratio = $total > 0 ? min(1, max(0, $done / $total)) : 0;

        return match ($phase) {
            'queued' => 0.0,
            'prepare' => 0.02,
            'mailbox', 'locate' => 0.04,
            'archive' => 0.14,
            'download' => 0.14 + (0.38 * $ratio),
            'upload' => 0.52 + (0.32 * $ratio),
            'extract' => 0.88,
            'sync' => 0.95,
            'copied', 'done' => 1.0,
            default => 0.08,
        };
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function buildLabel(array $state, string $detail): string
    {
        $email = (string) ($state['current_email'] ?? '');
        $index = (int) ($state['mailbox_index'] ?? 0);
        $total = (int) ($state['mailbox_total'] ?? 0);
        $prefix = $email !== '' && $total > 0
            ? $email.' ('.$index.'/'.$total.')'
            : ($email !== '' ? $email : 'Mail pull');

        $bytesDone = (int) ($state['bytes_done'] ?? 0);
        $bytesTotal = (int) ($state['bytes_total'] ?? 0);
        $phase = (string) ($state['phase'] ?? '');
        if (in_array($phase, ['download', 'upload'], true) && $bytesTotal > 0) {
            $pct = (int) round(($bytesDone / $bytesTotal) * 100);

            return $prefix.' — '.$phase.' '.$pct.'% · '.self::formatBytes($bytesDone).' / '.self::formatBytes($bytesTotal);
        }

        if ($detail !== '') {
            return $prefix.' — '.$detail;
        }

        return $prefix.' — '.$phase;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function appendLog(array &$state, string $line): void
    {
        $line = trim($line);
        if ($line === '') {
            return;
        }
        $entry = $this->line($line);
        $existing = (string) ($state['log'] ?? '');
        $combined = $existing === '' ? $entry : $existing."\n".$entry;
        if (strlen($combined) > self::LOG_MAX_BYTES) {
            $combined = substr($combined, -self::LOG_MAX_BYTES);
        }
        $state['log'] = $combined;
    }

    private function line(string $message): string
    {
        return '['.now()->format('H:i:s').'] '.$message;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function persist(Service $service, array $state, bool $force): void
    {
        $state['service_id'] = $service->id;
        $state['updated_at'] = now()->toIso8601String();
        $state['percent'] = $this->computePercent($state);
        Cache::put($this->cacheKey($service), $state, self::CACHE_TTL_SECONDS);

        $now = microtime(true);
        $last = $this->lastMetaWriteAt[$service->id] ?? 0.0;
        if (! $force && ($now - $last) < 1.0) {
            return;
        }

        $this->lastMetaWriteAt[$service->id] = $now;
        $metaState = $state;
        if (strlen((string) ($metaState['log'] ?? '')) > self::META_LOG_MAX_BYTES) {
            $metaState['log'] = substr((string) $metaState['log'], -self::META_LOG_MAX_BYTES);
        }
        $service->refresh();
        $meta = is_array($service->service_meta) ? $service->service_meta : [];
        $meta['mail_pull'] = $metaState;
        $service->update(['service_meta' => $meta]);
    }
}
