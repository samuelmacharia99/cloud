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
        $this->assertArrayNotHasKey('mem_limit', $compose['services']['mysql']);
        $this->assertSame('256M', $compose['services']['mysql']['mem_reservation']);
        $this->assertContains('--innodb-buffer-pool-size=256M', $compose['services']['mysql']['command']);
        $this->assertSame('always', $compose['services']['app-service']['restart']);
        $this->assertSame('CMD-SHELL', $compose['services']['mysql']['healthcheck']['test'][0]);
        $this->assertStringContainsString('127.0.0.1', $compose['services']['mysql']['healthcheck']['test'][1]);
        $this->assertSame('300s', $compose['services']['mysql']['healthcheck']['start_period']);
        $this->assertSame(
            ['mysql' => ['condition' => 'service_started']],
            $compose['services']['app-service']['depends_on']
        );
    }

    #[Test]
    public function it_generates_hermes_dashboard_and_api_secrets(): void
    {
        $service = new ContainerTemplateEnvironmentService;
        $template = (object) [
            'slug' => 'hermes',
            'environment_variables' => [],
        ];

        $env = $service->prepare($template, [], $this->makeService());

        $this->assertSame('1', $env['HERMES_DASHBOARD']);
        $this->assertSame('0.0.0.0', $env['HERMES_DASHBOARD_HOST']);
        $this->assertSame('admin', $env['HERMES_DASHBOARD_BASIC_AUTH_USERNAME']);
        $this->assertNotSame('', $env['HERMES_DASHBOARD_BASIC_AUTH_PASSWORD']);
        $this->assertNotSame('', $env['HERMES_DASHBOARD_BASIC_AUTH_SECRET']);
        $this->assertSame('true', $env['API_SERVER_ENABLED']);
        $this->assertSame('0.0.0.0', $env['API_SERVER_HOST']);
        $this->assertSame('127.0.0.1,::1,172.16.0.0/12,10.0.0.0/8', $env['FORWARDED_ALLOW_IPS']);
        $this->assertSame('180', $env['HERMES_WS_WRITE_TIMEOUT']);
        $this->assertGreaterThanOrEqual(8, strlen($env['API_SERVER_KEY']));

        $preserved = $service->prepare($template, [
            'HERMES_DASHBOARD_BASIC_AUTH_USERNAME' => 'ops',
            'HERMES_DASHBOARD_BASIC_AUTH_PASSWORD' => 'keep-me',
            'API_SERVER_KEY' => 'fixed-key-12345678',
        ], $this->makeService());

        $this->assertSame('ops', $preserved['HERMES_DASHBOARD_BASIC_AUTH_USERNAME']);
        $this->assertSame('keep-me', $preserved['HERMES_DASHBOARD_BASIC_AUTH_PASSWORD']);
        $this->assertSame('fixed-key-12345678', $preserved['API_SERVER_KEY']);
    }

    #[Test]
    public function it_generates_openclaw_gateway_token_and_lan_bind(): void
    {
        $service = new ContainerTemplateEnvironmentService;
        $template = (object) [
            'slug' => 'openclaw',
            'environment_variables' => [],
        ];

        $env = $service->prepare($template, [], $this->makeService());

        $this->assertSame('lan', $env['OPENCLAW_GATEWAY_BIND']);
        $this->assertSame('/home/node', $env['HOME']);
        $this->assertSame('/home/node/.openclaw', $env['OPENCLAW_STATE_DIR']);
        $this->assertNotSame('', $env['OPENCLAW_GATEWAY_TOKEN']);
        $this->assertSame(32, strlen($env['OPENCLAW_GATEWAY_TOKEN']));
    }

    #[Test]
    public function it_prepares_catalog_stack_secrets_and_database_aliases(): void
    {
        $service = new ContainerTemplateEnvironmentService;
        $user = new User(['email' => 'ops@example.com']);

        $n8n = $service->prepare((object) ['slug' => 'n8n', 'environment_variables' => []], [], $this->makeService($user), 32001);
        $this->assertSame('https', $n8n['N8N_PROTOCOL']);
        $this->assertSame('http://localhost:32001', $n8n['WEBHOOK_URL']);
        $this->assertNotSame('', $n8n['N8N_ENCRYPTION_KEY']);
        $this->assertNotSame('', $n8n['N8N_BASIC_AUTH_PASSWORD']);

        $directus = $service->prepare((object) ['slug' => 'directus', 'environment_variables' => []], [
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => 'db',
            'DB_USERNAME' => 'directus',
            'DB_PASSWORD' => 'secret-db',
            'DB_DATABASE' => 'directus',
        ], $this->makeService($user));
        $this->assertSame('pg', $directus['DB_CLIENT']);
        $this->assertSame('directus', $directus['DB_USER']);
        $this->assertSame('ops@example.com', $directus['ADMIN_EMAIL']);
        $this->assertGreaterThanOrEqual(32, strlen($directus['SECRET']));

        $chatwoot = $service->prepare((object) ['slug' => 'chatwoot', 'environment_variables' => []], [
            'DB_HOST' => 'db',
            'DB_PORT' => '5432',
            'DB_DATABASE' => 'chatwoot',
            'DB_USERNAME' => 'chatwoot',
            'DB_PASSWORD' => 'cw-pass',
        ], $this->makeService($user), 32002);
        $this->assertSame('db', $chatwoot['POSTGRES_HOST']);
        $this->assertSame('chatwoot', $chatwoot['POSTGRES_DATABASE']);
        $this->assertSame('redis://redis:6379', $chatwoot['REDIS_URL']);
        $this->assertSame(64, strlen($chatwoot['SECRET_KEY_BASE']));

        $odoo = $service->prepare((object) ['slug' => 'odoo', 'environment_variables' => []], [
            'DB_HOST' => 'db',
            'DB_USERNAME' => 'odoo',
            'DB_PASSWORD' => 'odoo-pass',
        ], $this->makeService());
        $this->assertSame('db', $odoo['HOST']);
        $this->assertSame('odoo', $odoo['USER']);
        $this->assertSame('odoo-pass', $odoo['PASSWORD']);

        $erpnext = $service->prepare((object) ['slug' => 'erpnext', 'environment_variables' => []], [], $this->makeService());
        $this->assertSame('backend:8000', $erpnext['BACKEND']);
        $this->assertNotSame('', $erpnext['MYSQL_ROOT_PASSWORD']);
        $this->assertNotSame('', $erpnext['ERPNEXT_ADMIN_PASSWORD']);
    }

    #[Test]
    public function it_maps_ollama_model_size_to_official_library_tags(): void
    {
        $service = new ContainerTemplateEnvironmentService;

        $seven = $service->prepare(
            (object) ['slug' => 'ollama', 'environment_variables' => []],
            [],
            $this->makeService(null, ['selected_version' => '7b'])
        );
        $this->assertSame('0.0.0.0:11434', $seven['OLLAMA_HOST']);
        $this->assertSame('mistral:7b', $seven['OLLAMA_MODEL']);
        $this->assertSame('65536', $seven['OLLAMA_CONTEXT_LENGTH']);

        $eight = $service->prepare(
            (object) ['slug' => 'ollama', 'environment_variables' => []],
            [],
            $this->makeService(null, ['selected_version' => '8b'])
        );
        $this->assertSame('ministral-3:8b', $eight['OLLAMA_MODEL']);
    }

    #[Test]
    public function it_sets_npm_cache_and_file_cache_defaults_for_laravel(): void
    {
        $service = new ContainerTemplateEnvironmentService;
        $template = (object) [
            'slug' => 'laravel',
            'environment_variables' => [],
        ];

        $env = $service->prepare($template, [], $this->makeService());

        $this->assertSame('/tmp', $env['HOME']);
        $this->assertSame('/tmp/.npm', $env['NPM_CONFIG_CACHE']);
        $this->assertSame('file', $env['CACHE_STORE']);
        $this->assertSame('file', $env['CACHE_DRIVER']);
    }

    private function makeService(?User $user = null, array $meta = []): Service
    {
        $service = new Service;
        $service->id = 1;
        $service->user_id = 1;
        $service->service_meta = $meta;
        $service->setRelation('user', $user ?? new User(['email' => 'admin@example.com']));

        return $service;
    }
}
