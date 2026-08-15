<?php

namespace App\Jobs;

use App\Models\ContainerGitPull;
use App\Services\Provisioning\ContainerGitRepositoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class PullContainerGitRepositoryJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    /**
     * Retry transient SSH/network failures. Permanent build/install errors should not loop.
     */
    public int $tries = 100;

    public function __construct(public int $gitPullId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [20, 60];
    }

    /**
     * Keep old and restarted jobs for one service from mutating /app together.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        $serviceId = ContainerGitPull::query()
            ->whereKey($this->gitPullId)
            ->value('service_id');

        return [
            (new WithoutOverlapping('container-git-service-'.($serviceId ?: $this->gitPullId)))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 300),
        ];
    }

    public function handle(ContainerGitRepositoryService $service): void
    {
        $pull = ContainerGitPull::find($this->gitPullId);

        if (! $pull) {
            return;
        }

        // Allow a fresh attempt after a transient failure marked the pull as failed.
        if ($pull->status === ContainerGitPull::STATUS_FAILED) {
            if (! $this->shouldRetryFailedPull($pull) || $this->transientRetryCount($pull) >= 3) {
                return;
            }

            $pull->update([
                'status' => ContainerGitPull::STATUS_PENDING,
                'error_message' => null,
                'completed_at' => null,
            ]);
            $pull->resetStepsForRetry();
            $pull->appendLog('Retrying Git pull after a transient failure.');
        }

        if (! $pull->isActive() && $pull->status !== ContainerGitPull::STATUS_PENDING) {
            return;
        }

        $service->runPull($pull);

        $pull->refresh();
        if ($pull->status === ContainerGitPull::STATUS_FAILED
            && $this->isTransientFailureMessage((string) $pull->error_message)
            && $this->incrementTransientRetryCount($pull) < 3
        ) {
            throw new \RuntimeException((string) $pull->error_message);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $pull = ContainerGitPull::find($this->gitPullId);
        if (! $pull || $pull->status === ContainerGitPull::STATUS_CANCELLED) {
            return;
        }

        try {
            app(ContainerGitRepositoryService::class)->recoverInterruptedPull($pull);
        } catch (Throwable $recoveryError) {
            $pull->appendLog('Application recovery after worker failure failed: '.$recoveryError->getMessage());
        }

        $pull->update([
            'status' => ContainerGitPull::STATUS_FAILED,
            'error_message' => $exception?->getMessage() ?: 'Git pull worker timed out or stopped unexpectedly.',
            'completed_at' => now(),
        ]);
        $pull->appendLog('Git pull job failed: '.($pull->error_message ?? 'Worker stopped unexpectedly.'));
    }

    private function shouldRetryFailedPull(ContainerGitPull $pull): bool
    {
        return $this->isTransientFailureMessage((string) $pull->error_message);
    }

    private function transientRetryCount(ContainerGitPull $pull): int
    {
        $options = is_array($pull->options) ? $pull->options : [];

        return (int) ($options['transient_retry_count'] ?? 0);
    }

    private function incrementTransientRetryCount(ContainerGitPull $pull): int
    {
        $options = is_array($pull->options) ? $pull->options : [];
        $options['transient_retry_count'] = $this->transientRetryCount($pull) + 1;
        $pull->update(['options' => $options]);

        return (int) $options['transient_retry_count'];
    }

    public function isTransientFailureMessage(string $message): bool
    {
        $message = strtolower($message);

        if ($message === '') {
            return false;
        }

        foreach ([
            'ssh connection',
            'ssh command failed',
            'connection timed out',
            'connection reset',
            'broken pipe',
            'temporarily unavailable',
            'network is unreachable',
            'could not resolve host',
            'tls handshake timeout',
            'i/o timeout',
            'remote hung up',
            'early eof',
            'unable to connect',
        ] as $needle) {
            if (str_contains($message, $needle)) {
                // Install/build failures also wrap SSHCommandException — exclude those.
                if (str_contains($message, 'post-pull step failed')
                    || str_contains($message, 'npm ')
                    || str_contains($message, 'composer ')
                    || str_contains($message, 'bundle install')
                    || str_contains($message, 'pip install')
                    || str_contains($message, 'next build')
                    || str_contains($message, 'lockfile')
                ) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }
}
