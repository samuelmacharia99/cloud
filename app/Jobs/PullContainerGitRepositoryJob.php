<?php

namespace App\Jobs;

use App\Models\ContainerGitPull;
use App\Services\Provisioning\ContainerGitRepositoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PullContainerGitRepositoryJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /**
     * Retry transient SSH/network failures. Permanent build/install errors should not loop.
     */
    public int $tries = 3;

    public function __construct(public int $gitPullId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [20, 60];
    }

    public function handle(ContainerGitRepositoryService $service): void
    {
        $pull = ContainerGitPull::find($this->gitPullId);

        if (! $pull) {
            return;
        }

        // Allow a fresh attempt after a transient failure marked the pull as failed.
        if ($pull->status === ContainerGitPull::STATUS_FAILED && $this->attempts() > 1) {
            if (! $this->shouldRetryFailedPull($pull)) {
                return;
            }

            $pull->update([
                'status' => ContainerGitPull::STATUS_PENDING,
                'error_message' => null,
                'completed_at' => null,
            ]);
            $pull->appendLog('Retrying Git pull after a transient failure (attempt '.$this->attempts().').');
        }

        if (! $pull->isActive() && $pull->status !== ContainerGitPull::STATUS_PENDING) {
            return;
        }

        $service->runPull($pull);

        $pull->refresh();
        if ($pull->status === ContainerGitPull::STATUS_FAILED
            && $this->attempts() < $this->tries
            && $this->isTransientFailureMessage((string) $pull->error_message)
        ) {
            throw new \RuntimeException((string) $pull->error_message);
        }
    }

    private function shouldRetryFailedPull(ContainerGitPull $pull): bool
    {
        return $this->isTransientFailureMessage((string) $pull->error_message);
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
