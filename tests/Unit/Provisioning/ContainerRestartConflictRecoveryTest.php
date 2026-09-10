<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Services\Provisioning\ContainerDeploymentService;
use App\Services\SSH\SSHService;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A recreate that dies between Compose renaming the old container and creating
 * the new one leaves "<hash>_<project>" behind. Every later recreate is then
 * refused the same temporary name, so the site stays down and the alert repeats
 * until an operator removes it by hand.
 *
 * `compose up` already recovered from this. Restart did not, which is why one
 * WordPress site alerted every two minutes.
 */
class ContainerRestartConflictRecoveryTest extends TestCase
{
    private const CONFLICT = 'SSH command failed: cd \'/opt/talksasa/containers/user-246-service-22-wordpress\' '
        .'&& docker compose -f docker-compose.yml up -d --no-deps --pull never --force-recreate'."\n"
        .'Error response from daemon: Conflict. The container name '
        .'"/e0260902ef09_user-246-service-22-wordpress" is already in use by container '
        .'"97ecea7dff29bfbf18fcae5de7395c9fbf3519b22c965fd3697eba954b3946dc". '
        .'You have to remove (or rename) that container to be able to reuse that name.';

    #[Test]
    public function it_recognises_the_rename_leftover_conflict(): void
    {
        $this->assertTrue(
            app(ContainerDeploymentService::class)->isDockerContainerNameConflict(self::CONFLICT)
        );
    }

    #[Test]
    public function it_names_both_the_leftover_and_the_container_holding_the_name(): void
    {
        $refs = app(ContainerDeploymentService::class)->conflictingDockerRefsFromError(self::CONFLICT);

        $this->assertContains('e0260902ef09_user-246-service-22-wordpress', $refs);
        $this->assertContains('97ecea7dff29bfbf18fcae5de7395c9fbf3519b22c965fd3697eba954b3946dc', $refs);
    }

    #[Test]
    public function a_restart_clears_the_leftover_and_tries_again(): void
    {
        $commands = [];
        $ssh = $this->sshFailingOnce($commands, self::CONFLICT);

        app(ContainerDeploymentService::class)->restartAppService($ssh, $this->deployment());

        $joined = implode("\n", $commands);
        $this->assertStringContainsString('docker rm -f', $joined, 'The leftover is removed.');
        $this->assertStringContainsString('e0260902ef09_user-246-service-22-wordpress', $joined);
        $this->assertSame(
            2,
            substr_count($joined, '--force-recreate'),
            'The recreate is attempted again once the name is free.'
        );
    }

    #[Test]
    public function a_restart_that_fails_for_any_other_reason_still_fails(): void
    {
        $commands = [];
        $ssh = $this->sshFailingOnce($commands, 'no space left on device');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no space left on device');

        app(ContainerDeploymentService::class)->restartAppService($ssh, $this->deployment());
    }

    private function deployment(): ContainerDeployment
    {
        $deployment = new ContainerDeployment;
        $deployment->container_name = 'user-246-service-22-wordpress';

        return $deployment;
    }

    /**
     * An SSH double whose first recreate fails and whose second succeeds.
     *
     * @param  list<string>  $commands
     */
    private function sshFailingOnce(array &$commands, string $firstError): SSHService
    {
        $recreates = 0;

        $ssh = Mockery::mock(SSHService::class);
        $ssh->shouldReceive('exec')->andReturnUsing(
            function (string $command) use (&$commands, &$recreates, $firstError): string {
                $commands[] = $command;

                if (str_contains($command, '--force-recreate')) {
                    $recreates++;
                    if ($recreates === 1) {
                        throw new \RuntimeException($firstError);
                    }
                }

                return '';
            }
        );

        return $ssh;
    }
}
