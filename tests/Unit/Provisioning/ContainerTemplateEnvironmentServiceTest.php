<?php

namespace Tests\Unit\Provisioning;

use App\Models\ContainerTemplate;
use App\Models\Service;
use App\Models\User;
use App\Services\Provisioning\ContainerTemplateEnvironmentService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContainerTemplateEnvironmentServiceTest extends TestCase
{
    #[Test]
    public function it_generates_strapi_secrets_when_missing(): void
    {
        $service = new ContainerTemplateEnvironmentService;
        $template = (object) [
            'slug' => 'strapi',
            'environment_variables' => [
                ['key' => 'APP_KEYS', 'required' => true, 'secret' => true],
                ['key' => 'API_TOKEN_SALT', 'required' => true, 'secret' => true],
                ['key' => 'ADMIN_JWT_SECRET', 'required' => true, 'secret' => true],
            ],
        ];

        $env = $service->prepare($template, [], $this->makeService());

        $this->assertNotSame('', $env['APP_KEYS']);
        $this->assertSame(4, count(explode(',', $env['APP_KEYS'])));
        $this->assertNotSame('', $env['API_TOKEN_SALT']);
        $this->assertNotSame('', $env['ADMIN_JWT_SECRET']);
        $this->assertNotSame('', $env['JWT_SECRET']);
        $this->assertNotSame('', $env['TRANSFER_TOKEN_SALT']);
    }

    #[Test]
    public function it_generates_ghost_and_wordpress_defaults(): void
    {
        $service = new ContainerTemplateEnvironmentService;
        $user = new User(['email' => 'writer@example.com']);

        $ghostTemplate = (object) [
            'slug' => 'ghost',
            'environment_variables' => [
                ['key' => 'url', 'required' => true, 'secret' => false],
                ['key' => 'mail__from', 'required' => true, 'secret' => false],
            ],
        ];

        $ghostEnv = $service->prepare($ghostTemplate, [], $this->makeService($user), 31000);
        $this->assertSame('http://localhost:31000', $ghostEnv['url']);
        $this->assertSame('writer@example.com', $ghostEnv['mail__from']);

        $wordpressTemplate = (object) [
            'slug' => 'wordpress',
            'environment_variables' => [],
        ];

        $wordpressEnv = $service->prepare($wordpressTemplate, [], $this->makeService($user));
        $this->assertNotSame('', $wordpressEnv['WORDPRESS_DB_PASSWORD']);
        $this->assertNotSame('', $wordpressEnv['WORDPRESS_ADMIN_PASSWORD']);
        $this->assertNotSame('', $wordpressEnv['MYSQL_ROOT_PASSWORD']);
    }

    #[Test]
    public function it_detects_embedded_database_sidecars_and_syncs_wordpress_mysql_env(): void
    {
        $service = new ContainerTemplateEnvironmentService;
        $template = new ContainerTemplate([
            'slug' => 'wordpress',
            'compose_services' => [
                'mysql' => ['image' => 'mysql:8.0'],
            ],
        ]);

        $this->assertTrue($service->templateDefinesDatabaseSidecar($template));

        $compose = [
            'services' => [
                'app-service' => ['image' => 'wordpress:latest'],
                'mysql' => ['image' => 'mysql:8.0', 'environment' => []],
            ],
        ];

        $service->syncEmbeddedDatabaseSidecar($compose, $template, [
            'WORDPRESS_DB_NAME' => 'wp_app',
            'WORDPRESS_DB_USER' => 'wp_user',
            'WORDPRESS_DB_PASSWORD' => 'wp-secret',
            'MYSQL_ROOT_PASSWORD' => 'root-secret',
        ], 'app-service');

        $this->assertSame('wp_app', $compose['services']['mysql']['environment']['MYSQL_DATABASE']);
        $this->assertSame('wp_user', $compose['services']['mysql']['environment']['MYSQL_USER']);
        $this->assertSame('wp-secret', $compose['services']['mysql']['environment']['MYSQL_PASSWORD']);
        $this->assertSame('root-secret', $compose['services']['mysql']['environment']['MYSQL_ROOT_PASSWORD']);
        $this->assertSame('app-service-mysql', $compose['services']['mysql']['container_name']);
        $this->assertSame('always', $compose['services']['mysql']['restart']);
        $this->assertSame('1g', $compose['services']['mysql']['mem_limit']);
        $this->assertSame('always', $compose['services']['app-service']['restart']);
        $this->assertSame('CMD-SHELL', $compose['services']['mysql']['healthcheck']['test'][0]);
        $this->assertStringContainsString('127.0.0.1', $compose['services']['mysql']['healthcheck']['test'][1]);
        $this->assertSame('300s', $compose['services']['mysql']['healthcheck']['start_period']);
        $this->assertSame(
            ['mysql' => ['condition' => 'service_healthy']],
            $compose['services']['app-service']['depends_on']
        );
    }

    private function makeService(?User $user = null): Service
    {
        $service = new Service;
        $service->id = 1;
        $service->user_id = 1;
        $service->setRelation('user', $user ?? new User(['email' => 'admin@example.com']));

        return $service;
    }
}
