<?php

namespace Tests\Unit\Provisioning;

use App\Exceptions\SSH\SSHCommandException;
use App\Services\Provisioning\ContainerMigrationService;
use App\Services\SSH\SSHService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ContainerMigrationServiceTest extends TestCase
{
    #[Test]
    public function missing_target_compose_directory_does_not_abort_unpack(): void
    {
        $ssh = $this->createMock(SSHService::class);
        $ssh->expects($this->once())
            ->method('exec')
            ->with(
                $this->stringContains('docker compose -f docker-compose.yml down -v'),
                120
            )
            ->willThrowException(new SSHCommandException(
                'cd /opt/talksasa/containers/user-493-service-454-nodejs && docker compose -f docker-compose.yml down -v',
                'bash: line 1: cd: /opt/talksasa/containers/user-493-service-454-nodejs: No such file or directory',
                'Command exited with status 1'
            ));

        (new ContainerMigrationService)->stopComposeIfPresent(
            $ssh,
            '/opt/talksasa/containers/user-493-service-454-nodejs'
        );
    }
}
