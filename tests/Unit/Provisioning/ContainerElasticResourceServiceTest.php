<?php

namespace Tests\Unit\Provisioning;

use App\Services\Provisioning\ContainerElasticResourceService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ContainerElasticResourceServiceTest extends TestCase
{
    #[Test]
    public function hard_limits_become_soft_reservations_and_cpu_shares(): void
    {
        $compose = [
            'services' => [
                'wordpress' => [
                    'image' => 'wordpress:latest',
                    'mem_limit' => '1024M',
                    'cpus' => 1,
                    'deploy' => [
                        'resources' => [
                            'limits' => ['cpus' => '1', 'memory' => '1024M'],
                        ],
                    ],
                ],
                'mysql' => [
                    'image' => 'mysql:8',
                    'mem_limit' => '512M',
                    'cpus' => 1,
                ],
            ],
        ];

        $service = new ContainerElasticResourceService;
        $service->apply($compose, 'wordpress', 1, 1024);

        foreach ($compose['services'] as $runtime) {
            $this->assertArrayNotHasKey('mem_limit', $runtime);
            $this->assertArrayNotHasKey('cpus', $runtime);
            $this->assertArrayNotHasKey('limits', $runtime['deploy']['resources']);
            $this->assertArrayHasKey('mem_reservation', $runtime);
            $this->assertArrayHasKey('cpu_shares', $runtime);
        }

        $this->assertSame('elastic-v1', $compose['x-talksasa-resource-policy']['version']);
        $this->assertSame('soft-reservations', $compose['x-talksasa-resource-policy']['mode']);
        $this->assertSame('wordpress-mysql', $compose['services']['mysql']['container_name']);
        $this->assertGreaterThan(
            (int) $compose['services']['mysql']['mem_reservation'],
            (int) $compose['services']['wordpress']['mem_reservation']
        );
    }

    #[Test]
    public function existing_yaml_is_upgraded_without_losing_commands_or_volumes(): void
    {
        $legacy = Yaml::dump([
            'services' => [
                'app' => [
                    'image' => 'node:20',
                    'command' => ['npm', 'start'],
                    'volumes' => ['/opt/app:/app'],
                    'mem_limit' => '512M',
                    'cpus' => 0.5,
                ],
            ],
        ], 10, 2);

        $service = new ContainerElasticResourceService;
        $updated = $service->applyToYaml($legacy, 'app', 0.5, 512);
        $parsed = Yaml::parse($updated);

        $this->assertSame(['npm', 'start'], $parsed['services']['app']['command']);
        $this->assertSame(['/opt/app:/app'], $parsed['services']['app']['volumes']);
        $this->assertArrayNotHasKey('mem_limit', $parsed['services']['app']);
        $this->assertTrue($service->isCurrent($updated));
        $this->assertFalse($service->isCurrent($legacy));
    }
}
