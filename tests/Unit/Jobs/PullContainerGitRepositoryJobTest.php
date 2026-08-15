<?php

namespace Tests\Unit\Jobs;

use App\Jobs\PullContainerGitRepositoryJob;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PullContainerGitRepositoryJobTest extends TestCase
{
    #[Test]
    public function it_retries_transient_ssh_and_network_failures(): void
    {
        $job = new PullContainerGitRepositoryJob(1);

        $this->assertTrue($job->isTransientFailureMessage('SSH connection timed out to node'));
        $this->assertTrue($job->isTransientFailureMessage('Unable to connect to remote host'));
        $this->assertTrue($job->isTransientFailureMessage('Connection reset by peer'));
        // Overlap releases consume queue attempts; transient execution retries
        // are independently capped at three in the pull options.
        $this->assertSame(100, $job->tries);
        $this->assertSame(3600, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([20, 60], $job->backoff());
    }

    #[Test]
    public function it_does_not_retry_install_or_build_failures(): void
    {
        $job = new PullContainerGitRepositoryJob(1);

        $this->assertFalse($job->isTransientFailureMessage(
            'Node post-pull step failed: SSH command failed: npm run build exited 127'
        ));
        $this->assertFalse($job->isTransientFailureMessage(
            'Python post-pull step failed: pip install failed'
        ));
        $this->assertFalse($job->isTransientFailureMessage(
            'This repository uses Yarn (yarn.lock) without package-lock.json.'
        ));
        $this->assertFalse($job->isTransientFailureMessage(''));
    }
}
