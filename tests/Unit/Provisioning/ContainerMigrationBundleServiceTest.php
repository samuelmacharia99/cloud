<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerDeployment;
use App\Models\ContainerDomain;
use App\Services\Provisioning\ContainerMigrationBundleService;
use App\Services\SSH\SSHService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerMigrationBundleServiceTest extends TestCase
{
    #[Test]
    public function it_accepts_only_safe_unique_docker_volume_names(): void
    {
        $service = new ContainerMigrationBundleService;

        $this->assertSame(
            ['project_db-data', 'project_uploads'],
            $service->parseVolumeList(
                "project_db-data\nproject_uploads\nproject_db-data\n../../etc\nbad name\n"
            ),
        );
    }

    #[Test]
    public function transfer_stops_before_target_upload_when_local_checksum_changes(): void
    {
        $service = new ContainerMigrationBundleService;
        $source = $this->createMock(SSHService::class);
        $target = $this->createMock(SSHService::class);
        $local = sys_get_temp_dir().'/migration-corrupt-'.uniqid().'.tar.gz';
        $source->expects($this->once())
            ->method('downloadToLocal')
            ->willReturnCallback(static function (string $remote, string $path): void {
                file_put_contents($path, 'corrupt');
            });
        $target->expects($this->never())->method('uploadFromLocal');

        try {
            $service->transfer(
                $source,
                $target,
                '/opt/talksasa/migrations/test.tar.gz',
                $local,
                ['bytes' => 7, 'checksum' => str_repeat('a', 64)],
            );
            $this->fail('Expected checksum validation to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('checksum or size changed', $e->getMessage());
        } finally {
            @unlink($local);
        }
    }

    #[Test]
    public function preflight_refuses_to_overwrite_a_stale_target_copy(): void
    {
        $service = new ContainerMigrationBundleService;
        $deployment = new ContainerDeployment([
            'container_name' => 'user-1-service-454-nodejs',
            'assigned_port' => 30174,
        ]);
        $source = $this->createMock(SSHService::class);
        $source->method('exec')->willReturn('');
        $target = $this->createMock(SSHService::class);
        $target->method('exec')->willReturnCallback(
            static fn (string $command): string => str_contains($command, 'docker ps -aq --filter name=')
                ? 'yes'
                : '',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already contains this service path or container name');
        $service->preflight($source, $target, $deployment);
    }

    #[Test]
    public function preflight_rejects_insufficient_target_disk_before_downtime(): void
    {
        $service = new ContainerMigrationBundleService;
        $deployment = new ContainerDeployment([
            'container_name' => 'user-1-service-454-nodejs',
            'assigned_port' => null,
        ]);
        $source = $this->createMock(SSHService::class);
        $source->method('exec')->willReturnCallback(static function (string $command): string {
            if (str_contains($command, 'du -sb')) {
                return '104857600';
            }
            if (str_contains($command, 'df -PB1')) {
                return '10737418240';
            }

            return '';
        });
        $target = $this->createMock(SSHService::class);
        $target->method('exec')->willReturnCallback(static function (string $command): string {
            if (str_contains($command, 'docker ps -aq --filter name=')) {
                return 'no';
            }
            if (str_contains($command, 'df -PB1')) {
                return '1024';
            }

            return '';
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('insufficient free disk space');
        $service->preflight($source, $target, $deployment);
    }

    #[Test]
    public function preflight_rejects_invalid_target_nginx_before_downtime(): void
    {
        $service = new ContainerMigrationBundleService;
        $deployment = new ContainerDeployment([
            'container_name' => 'user-1-service-454-nodejs',
            'assigned_port' => null,
        ]);
        $deployment->setRelation('domains', collect([
            new ContainerDomain([
                'domain' => 'carslynk.com',
                'status' => 'active',
                'ssl_enabled' => false,
            ]),
        ]));

        $source = $this->createMock(SSHService::class);
        $source->method('exec')->willReturn('');
        $target = $this->createMock(SSHService::class);
        $target->method('exec')->willReturnCallback(static function (string $command): string {
            if (str_contains($command, 'command -v nginx')) {
                return 'yes';
            }

            if (str_contains($command, 'nginx -t')) {
                throw new \RuntimeException('missing certificate');
            }

            return '';
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target nginx configuration is invalid');
        $service->preflight($source, $target, $deployment);
    }

    #[Test]
    public function preflight_rejects_missing_target_certificate_before_downtime(): void
    {
        $service = new ContainerMigrationBundleService;
        $deployment = new ContainerDeployment([
            'container_name' => 'user-1-service-454-nodejs',
            'assigned_port' => null,
        ]);
        $deployment->setRelation('domains', collect([
            new ContainerDomain([
                'domain' => 'carslynk.com',
                'status' => 'active',
                'ssl_enabled' => true,
                'ssl_certificate_path' => '/etc/letsencrypt/live/carslynk.com/fullchain.pem',
                'ssl_key_path' => '/etc/letsencrypt/live/carslynk.com/privkey.pem',
            ]),
        ]));

        $source = $this->createMock(SSHService::class);
        $source->method('exec')->willReturnCallback(static function (string $command): string {
            return str_contains($command, 'base64 -w0') ? 'certificate-archive' : '';
        });
        $target = $this->createMock(SSHService::class);
        $target->method('exec')->willReturnCallback(static function (string $command): string {
            if (str_contains($command, 'command -v nginx')) {
                return 'yes';
            }

            if (str_contains($command, 'nginx -t')) {
                return 'syntax is ok';
            }

            if (str_contains($command, 'fullchain.pem')) {
                return 'no';
            }

            return '';
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target node is missing the SSL certificate for carslynk.com');
        $service->preflight($source, $target, $deployment);
    }
}
