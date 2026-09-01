<?php

namespace Database\Seeders;

use App\Models\ContainerTemplate;
use Illuminate\Database\Seeder;

class ContainerTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // 1. WordPress with MySQL sidecar
        ContainerTemplate::firstOrCreate(
            ['slug' => 'wordpress'],
            [
                'name' => 'WordPress with MySQL',
                'description' => 'Full WordPress CMS with dedicated MySQL 8.0. Latest WordPress is installed automatically with generated admin credentials.',
                'category' => 'web',
                'docker_image' => 'wordpress:latest',
                'default_port' => 80,
                'required_ram_mb' => 1024,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 5,
                'versions' => [
                    'latest',
                    '6.6-php8.3-apache',
                    '6.5-php8.2-apache',
                    '6.4-php8.1-apache',
                ],
                'environment_variables' => [
                    [
                        'key' => 'WORDPRESS_DB_HOST',
                        'label' => 'Database Host',
                        'default' => 'mysql:3306',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'WORDPRESS_DB_NAME',
                        'label' => 'Database Name',
                        'default' => 'wordpress',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'WORDPRESS_DB_USER',
                        'label' => 'Database User',
                        'default' => 'wordpress',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'WORDPRESS_DB_PASSWORD',
                        'label' => 'Database Password',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                    [
                        'key' => 'WORDPRESS_ADMIN_EMAIL',
                        'label' => 'Admin Email',
                        'default' => '',
                        'required' => true,
                        'secret' => false,
                    ],
                    [
                        'key' => 'WORDPRESS_ADMIN_USER',
                        'label' => 'Admin Username',
                        'default' => 'admin',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'WORDPRESS_ADMIN_PASSWORD',
                        'label' => 'Admin Password',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                ],
                'volume_paths' => [
                    'wp_data' => '/var/www/html',
                    'wp_content' => '/var/www/html/wp-content',
                ],
                'compose_services' => [
                    'mysql' => [
                        'image' => 'mysql:8.0',
                        'container_name' => 'mysql-wordpress',
                        'restart' => 'always',
                        'mem_limit' => '512M',
                        'cpus' => 1.0,
                        'command' => [
                            '--innodb-buffer-pool-size=256M',
                            '--max-connections=50',
                            '--table-open-cache=200',
                            '--performance-schema=OFF',
                        ],
                        'environment' => [
                            'MYSQL_DATABASE' => 'wordpress',
                            'MYSQL_USER' => 'wordpress',
                            'MYSQL_PASSWORD' => 'wordpress_pass',
                            'MYSQL_ROOT_PASSWORD' => 'root_pass',
                        ],
                        'volumes' => [
                            'mysql_data:/var/lib/mysql',
                        ],
                        'healthcheck' => [
                            'test' => [
                                'CMD-SHELL',
                                'mysqladmin ping -h 127.0.0.1 -uroot -p"$$MYSQL_ROOT_PASSWORD" --silent',
                            ],
                            'interval' => '10s',
                            'timeout' => '5s',
                            'retries' => 30,
                            'start_period' => '300s',
                        ],
                    ],
                ],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 120,
                'is_active' => true,
                'order' => 1,
            ]
        );

        // 2. Ghost Blog
        ContainerTemplate::firstOrCreate(
            ['slug' => 'ghost'],
            [
                'name' => 'Ghost Blog',
                'description' => 'Lightweight, fast blogging platform. Perfect for writers and publishers.',
                'category' => 'web',
                'docker_image' => 'ghost:5-alpine',
                'default_port' => 2368,
                'required_ram_mb' => 256,
                'required_cpu_cores' => 0.5,
                'required_storage_gb' => 2,
                'environment_variables' => [
                    [
                        'key' => 'url',
                        'label' => 'Blog URL',
                        'default' => 'http://localhost',
                        'required' => true,
                        'secret' => false,
                    ],
                    [
                        'key' => 'mail__transport',
                        'label' => 'Mail Transport',
                        'default' => 'Direct',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'mail__from',
                        'label' => 'Mail From Address',
                        'default' => 'noreply@example.com',
                        'required' => true,
                        'secret' => false,
                    ],
                    [
                        'key' => 'database__client',
                        'label' => 'Database Client',
                        'default' => 'sqlite3',
                        'required' => false,
                        'secret' => false,
                    ],
                ],
                'volume_paths' => [
                    'ghost_content' => '/var/lib/ghost/content',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 120,
                'is_active' => true,
                'order' => 8,
            ]
        );

        // 3. Strapi CMS
        ContainerTemplate::firstOrCreate(
            ['slug' => 'strapi'],
            [
                'name' => 'Strapi Headless CMS',
                'description' => 'Open-source headless CMS. Perfect for APIs, mobile apps, and JAMstack.',
                'category' => 'web',
                'docker_image' => 'strapi/strapi:latest',
                'default_port' => 1337,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 3,
                'environment_variables' => [
                    [
                        'key' => 'NODE_ENV',
                        'label' => 'Environment',
                        'default' => 'production',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'APP_KEYS',
                        'label' => 'App Keys (comma-separated)',
                        'default' => '',
                        'required' => true,
                        'secret' => true,
                    ],
                    [
                        'key' => 'API_TOKEN_SALT',
                        'label' => 'API Token Salt',
                        'default' => '',
                        'required' => true,
                        'secret' => true,
                    ],
                    [
                        'key' => 'ADMIN_JWT_SECRET',
                        'label' => 'Admin JWT Secret',
                        'default' => '',
                        'required' => true,
                        'secret' => true,
                    ],
                ],
                'volume_paths' => [
                    'strapi_app' => '/srv/app',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 120,
                'is_active' => true,
                'order' => 9,
            ]
        );

        // 4. Node.js Application
        ContainerTemplate::firstOrCreate(
            ['slug' => 'nodejs'],
            [
                'name' => 'Node.js Application',
                'description' => 'Generic Node.js runtime. Deploy any Node.js application (Express, Fastify, Hapi, etc.).',
                'category' => 'web',
                'docker_image' => 'node:20-alpine',
                'default_port' => 3000,
                'required_ram_mb' => 256,
                'required_cpu_cores' => 0.5,
                'required_storage_gb' => 2,
                'versions' => [
                    '18-alpine',
                    '20-alpine',
                    '22-alpine',
                    '18-slim',
                    '20-slim',
                    '22-slim',
                    '18',
                    '20',
                    '22',
                ],
                'environment_variables' => [
                    [
                        'key' => 'NODE_ENV',
                        'label' => 'Environment',
                        'default' => 'production',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'PORT',
                        'label' => 'Application Port',
                        'default' => '3000',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'npm_config_production',
                        'label' => 'Production Dependencies',
                        'default' => 'false',
                        'required' => false,
                        'secret' => false,
                    ],
                ],
                'volume_paths' => [
                    'app_data' => '/app',
                ],
                'compose_services' => [],
                'setup_commands' => [
                    'npm install --omit=dev',
                ],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 180,
                'is_active' => true,
                'order' => 2,
            ]
        );

        // 5. Python Application
        ContainerTemplate::firstOrCreate(
            ['slug' => 'python'],
            [
                'name' => 'Python Application',
                'description' => 'Python runtime for Flask, Django, FastAPI applications.',
                'category' => 'web',
                'docker_image' => 'python:3.11-slim',
                'default_port' => 8000,
                'required_ram_mb' => 256,
                'required_cpu_cores' => 0.5,
                'required_storage_gb' => 2,
                'versions' => [
                    '3.10-slim',
                    '3.11-slim',
                    '3.12-slim',
                    '3.13-slim',
                ],
                'environment_variables' => [
                    [
                        'key' => 'PYTHONUNBUFFERED',
                        'label' => 'Python Output Buffering',
                        'default' => '1',
                        'required' => false,
                        'secret' => false,
                    ],
                ],
                'volume_paths' => [
                    'app_data' => '/app',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 180,
                'is_active' => true,
                'order' => 3,
            ]
        );

        // 6. PHP Application
        ContainerTemplate::firstOrCreate(
            ['slug' => 'php'],
            [
                'name' => 'PHP Application',
                'description' => 'Generic PHP runtime for modern apps and APIs.',
                'category' => 'web',
                'docker_image' => 'talksasa/php-runtime:8.3',
                'default_port' => 8080,
                'required_ram_mb' => 256,
                'required_cpu_cores' => 0.5,
                'required_storage_gb' => 2,
                'versions' => [
                    '8.1-cli',
                    '8.2-cli',
                    '8.3-cli',
                    '8.4-cli',
                ],
                'environment_variables' => [
                    [
                        'key' => 'APP_ENV',
                        'label' => 'Application Environment',
                        'default' => 'production',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'APP_PORT',
                        'label' => 'Application Port',
                        'default' => '8080',
                        'required' => false,
                        'secret' => false,
                    ],
                ],
                'volume_paths' => [
                    'app_data' => '/app',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 120,
                'is_active' => true,
                'order' => 6,
            ]
        );

        // 7. Laravel Application
        ContainerTemplate::firstOrCreate(
            ['slug' => 'laravel'],
            [
                'name' => 'Laravel Application',
                'description' => 'Laravel-ready runtime with flexible PHP versions. Latest Laravel is scaffolded automatically on new orders.',
                'category' => 'web',
                'docker_image' => 'talksasa/laravel-runtime:8.3',
                'default_port' => 8000,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 3,
                'versions' => [
                    '8.1-cli',
                    '8.2-cli',
                    '8.3-cli',
                    '8.4-cli',
                ],
                'environment_variables' => [
                    [
                        'key' => 'APP_ENV',
                        'label' => 'Laravel Environment',
                        'default' => 'production',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'APP_DEBUG',
                        'label' => 'Debug Mode',
                        'default' => 'false',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'APP_KEY',
                        'label' => 'Laravel APP_KEY',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                ],
                'volume_paths' => [
                    'app_data' => '/app',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => false,
                'health_check_timeout_seconds' => 240,
                'is_active' => true,
                'order' => 5,
            ]
        );

        // 8. Ruby Application
        ContainerTemplate::firstOrCreate(
            ['slug' => 'ruby'],
            [
                'name' => 'Ruby Application',
                'description' => 'Ruby runtime for Rails, Sinatra, and Rack applications.',
                'category' => 'web',
                'docker_image' => 'ruby:3.3-slim',
                'default_port' => 3000,
                'required_ram_mb' => 384,
                'required_cpu_cores' => 0.5,
                'required_storage_gb' => 2,
                'versions' => [
                    '3.1-slim',
                    '3.2-slim',
                    '3.3-slim',
                    '3.4-slim',
                ],
                'environment_variables' => [
                    [
                        'key' => 'RACK_ENV',
                        'label' => 'Rack Environment',
                        'default' => 'production',
                        'required' => false,
                        'secret' => false,
                    ],
                    [
                        'key' => 'RAILS_ENV',
                        'label' => 'Rails Environment',
                        'default' => 'production',
                        'required' => false,
                        'secret' => false,
                    ],
                ],
                'volume_paths' => [
                    'app_data' => '/app',
                ],
                'compose_services' => [],
                'setup_commands' => [
                    'bundle install --without development test',
                ],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 180,
                'is_active' => true,
                'order' => 7,
            ]
        );

        // 9. Static Website
        ContainerTemplate::firstOrCreate(
            ['slug' => 'static-site'],
            [
                'name' => 'Static Website (Nginx)',
                'description' => 'High-performance nginx server. Ideal for static sites, SPAs, and compiled frontend apps.',
                'category' => 'web',
                'docker_image' => 'nginx:alpine',
                'default_port' => 80,
                'required_ram_mb' => 64,
                'required_cpu_cores' => 0.1,
                'required_storage_gb' => 1,
                'environment_variables' => [],
                'volume_paths' => [
                    'web_root' => '/usr/share/nginx/html',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 90,
                'is_active' => true,
                'order' => 4,
            ]
        );

        $this->seedAgentStacks();
        $this->seedCatalogStacks();
    }

    public function seedAgentStacks(): void
    {
        foreach (self::agentStackDefinitions() as $slug => $attributes) {
            ContainerTemplate::query()->firstOrCreate(['slug' => $slug], $attributes);
        }
    }

    public function seedCatalogStacks(): void
    {
        foreach (self::catalogStackDefinitions() as $slug => $attributes) {
            ContainerTemplate::query()->firstOrCreate(['slug' => $slug], $attributes);
        }
    }

    /**
     * Official Hermes Agent and OpenClaw gateway images.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function agentStackDefinitions(): array
    {
        return [
            'hermes' => [
                'name' => 'Hermes Agent',
                'description' => 'Nous Research Hermes Agent — always-on AI gateway with a web dashboard. Requires a plan with at least 2 GB RAM. Add an LLM API key after deploy under Environment.',
                'category' => 'web',
                'docker_image' => 'nousresearch/hermes-agent:latest',
                'default_port' => 9119,
                'required_ram_mb' => 2048,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 10,
                'versions' => [
                    'latest',
                ],
                'environment_variables' => [
                    [
                        'key' => 'ANTHROPIC_API_KEY',
                        'label' => 'Anthropic API key',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                    [
                        'key' => 'OPENAI_API_KEY',
                        'label' => 'OpenAI API key',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                    [
                        'key' => 'TELEGRAM_BOT_TOKEN',
                        'label' => 'Telegram bot token',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                    [
                        'key' => 'HERMES_DASHBOARD_BASIC_AUTH_USERNAME',
                        'label' => 'Dashboard username',
                        'default' => 'admin',
                        'required' => false,
                        'secret' => false,
                    ],
                ],
                'volume_paths' => [
                    'hermes_data' => '/opt/data',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 300,
                'is_active' => true,
                'order' => 10,
            ],
            'openclaw' => [
                'name' => 'OpenClaw',
                'description' => 'OpenClaw gateway and Control UI. Requires a plan with at least 2 GB RAM. Add an LLM API key after deploy under Environment, then sign in with the generated gateway token.',
                'category' => 'web',
                'docker_image' => 'openclaw/openclaw:latest',
                'default_port' => 18789,
                'required_ram_mb' => 2048,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 10,
                'versions' => [
                    'latest',
                    'main',
                    'extended-stable',
                    'slim',
                    'latest-browser',
                ],
                'environment_variables' => [
                    [
                        'key' => 'OPENAI_API_KEY',
                        'label' => 'OpenAI API key',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                    [
                        'key' => 'ANTHROPIC_API_KEY',
                        'label' => 'Anthropic API key',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                    [
                        'key' => 'TELEGRAM_BOT_TOKEN',
                        'label' => 'Telegram bot token',
                        'default' => '',
                        'required' => false,
                        'secret' => true,
                    ],
                ],
                'volume_paths' => [
                    'openclaw_state' => '/home/node/.openclaw',
                    'openclaw_workspace' => '/home/node/.openclaw/workspace',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 300,
                'is_active' => true,
                'order' => 11,
            ],
        ];
    }

    /**
     * Ready-made and runtime stacks from the public catalog (n8n, Go, Directus, Chatwoot, Odoo, ERPNext, Ollama).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function catalogStackDefinitions(): array
    {
        $erpnextImage = 'frappe/erpnext:v15';
        $erpnextVolumes = [
            'erpnext_sites:/home/frappe/frappe-bench/sites',
            'erpnext_logs:/home/frappe/frappe-bench/logs',
        ];

        return [
            'n8n' => [
                'name' => 'n8n',
                'description' => 'Self-hosted workflow automation. SQLite is used by default. Requires a plan with at least 1 GB RAM. Set webhook URL after you attach a domain.',
                'category' => 'web',
                'docker_image' => 'n8nio/n8n:latest',
                'default_port' => 5678,
                'required_ram_mb' => 1024,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 5,
                'versions' => ['latest', 'stable'],
                'environment_variables' => [
                    self::envVar('N8N_BASIC_AUTH_USER', 'Editor username', 'admin'),
                    self::envVar('GENERIC_TIMEZONE', 'Timezone', 'Africa/Nairobi'),
                ],
                'volume_paths' => [
                    'n8n_data' => '/home/node/.n8n',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 180,
                'is_active' => true,
                'order' => 12,
            ],
            'go' => [
                'name' => 'Go Application',
                'description' => 'Go runtime for modules and services. Push a repository with go.mod; the platform runs the web process on port 8080.',
                'category' => 'web',
                'docker_image' => 'golang:1.23-bookworm',
                'default_port' => 8080,
                'required_ram_mb' => 256,
                'required_cpu_cores' => 0.5,
                'required_storage_gb' => 2,
                'versions' => ['1.22-bookworm', '1.23-bookworm', '1.24-bookworm'],
                'environment_variables' => [
                    self::envVar('PORT', 'Application port', '8080'),
                    self::envVar('GO111MODULE', 'Go modules', 'on'),
                ],
                'volume_paths' => [
                    'app_data' => '/app',
                ],
                'compose_services' => [],
                'setup_commands' => [
                    'go mod download',
                ],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 180,
                'is_active' => true,
                'order' => 13,
            ],
            'directus' => [
                'name' => 'Directus',
                'description' => 'Headless CMS and data studio. Requires MySQL, MariaDB, or PostgreSQL. Admin credentials are generated on first deploy.',
                'category' => 'web',
                'docker_image' => 'directus/directus:latest',
                'default_port' => 8055,
                'required_ram_mb' => 1024,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 5,
                'versions' => ['latest'],
                'environment_variables' => [
                    self::envVar('ADMIN_EMAIL', 'Admin email', '', true),
                ],
                'volume_paths' => [
                    'directus_uploads' => '/directus/uploads',
                    'directus_extensions' => '/directus/extensions',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 240,
                'is_active' => true,
                'order' => 14,
            ],
            'chatwoot' => [
                'name' => 'Chatwoot',
                'description' => 'Omnichannel inbox for live chat, email, and WhatsApp. Requires PostgreSQL. Redis and Sidekiq are provisioned automatically. Needs at least 2 GB RAM.',
                'category' => 'web',
                'docker_image' => 'chatwoot/chatwoot:latest',
                'default_port' => 3000,
                'required_ram_mb' => 2048,
                'required_cpu_cores' => 1.5,
                'required_storage_gb' => 10,
                'versions' => ['latest'],
                'environment_variables' => [],
                'volume_paths' => [
                    'chatwoot_storage' => '/app/storage',
                ],
                'compose_services' => [
                    'redis' => [
                        'image' => 'redis:7-alpine',
                        'restart' => 'always',
                        'volumes' => ['chatwoot_redis:/data'],
                    ],
                    'sidekiq' => [
                        'image' => 'chatwoot/chatwoot:latest',
                        'restart' => 'always',
                        'command' => ['bundle', 'exec', 'sidekiq', '-C', 'config/sidekiq.yml'],
                        'depends_on' => ['redis'],
                    ],
                ],
                'setup_commands' => [
                    'bundle exec rails db:chatwoot_prepare',
                ],
                'strict_health_check' => false,
                'health_check_timeout_seconds' => 300,
                'is_active' => true,
                'order' => 15,
            ],
            'odoo' => [
                'name' => 'Odoo',
                'description' => 'ERP for sales, inventory, accounting, and HR. Requires PostgreSQL. Create the company database from the Odoo setup screen after deploy. Needs at least 2 GB RAM.',
                'category' => 'web',
                'docker_image' => 'odoo:18',
                'default_port' => 8069,
                'required_ram_mb' => 2048,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 10,
                'versions' => ['16', '17', '18'],
                'environment_variables' => [],
                'volume_paths' => [
                    'odoo_data' => '/var/lib/odoo',
                    'odoo_addons' => '/mnt/extra-addons',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 240,
                'is_active' => true,
                'order' => 16,
            ],
            'erpnext' => [
                'name' => 'ERPNext',
                'description' => 'Frappe ERPNext with MariaDB, Redis, workers, and a default site. First login is Administrator plus the generated admin password. Requires a plan with at least 4 GB RAM.',
                'category' => 'web',
                'docker_image' => $erpnextImage,
                'default_port' => 8080,
                'required_ram_mb' => 4096,
                'required_cpu_cores' => 2.0,
                'required_storage_gb' => 20,
                'versions' => ['v15', 'v16'],
                'environment_variables' => [],
                'volume_paths' => [
                    'erpnext_sites' => '/home/frappe/frappe-bench/sites',
                    'erpnext_logs' => '/home/frappe/frappe-bench/logs',
                ],
                'compose_services' => [
                    'db' => [
                        'image' => 'mariadb:11.8',
                        'restart' => 'always',
                        'command' => [
                            '--character-set-server=utf8mb4',
                            '--collation-server=utf8mb4_unicode_ci',
                            '--skip-character-set-client-handshake',
                        ],
                        'environment' => [
                            'MYSQL_ROOT_PASSWORD' => 'changeme',
                            'MARIADB_ROOT_PASSWORD' => 'changeme',
                        ],
                        'volumes' => ['erpnext_db:/var/lib/mysql'],
                    ],
                    'redis-cache' => [
                        'image' => 'redis:6.2-alpine',
                        'restart' => 'always',
                    ],
                    'redis-queue' => [
                        'image' => 'redis:6.2-alpine',
                        'restart' => 'always',
                    ],
                    'backend' => [
                        'image' => $erpnextImage,
                        'restart' => 'always',
                        'volumes' => $erpnextVolumes,
                        'depends_on' => ['db', 'redis-cache', 'redis-queue'],
                    ],
                    'websocket' => [
                        'image' => $erpnextImage,
                        'restart' => 'always',
                        'command' => ['node', '/home/frappe/frappe-bench/apps/frappe/socketio.js'],
                        'volumes' => $erpnextVolumes,
                        'depends_on' => ['backend'],
                    ],
                    'queue' => [
                        'image' => $erpnextImage,
                        'restart' => 'always',
                        'command' => ['bench', 'worker', '--queue', 'long,default,short'],
                        'volumes' => $erpnextVolumes,
                        'depends_on' => ['backend'],
                    ],
                    'scheduler' => [
                        'image' => $erpnextImage,
                        'restart' => 'always',
                        'command' => ['bench', 'schedule'],
                        'volumes' => $erpnextVolumes,
                        'depends_on' => ['backend'],
                    ],
                    'create-site' => [
                        'image' => $erpnextImage,
                        'restart' => 'no',
                        'volumes' => $erpnextVolumes,
                        'depends_on' => ['db', 'redis-cache', 'redis-queue'],
                        'entrypoint' => ['bash', '-c'],
                        'command' => ['echo waiting-for-sync'],
                    ],
                ],
                'setup_commands' => [],
                'strict_health_check' => false,
                'health_check_timeout_seconds' => 420,
                'is_active' => true,
                'order' => 17,
            ],
            'ollama' => [
                'name' => 'Ollama',
                'description' => 'Run Mistral-family models on your plan via Ollama. Choose 7B or 8B at deploy. Needs at least 8 GB RAM (16 GB recommended for 8B). Models are pulled after the API starts.',
                'category' => 'web',
                'docker_image' => 'ollama/ollama:latest',
                'default_port' => 11434,
                'required_ram_mb' => 8192,
                'required_cpu_cores' => 2.0,
                'required_storage_gb' => 20,
                'versions' => [
                    '7b',
                    '8b',
                ],
                'environment_variables' => [],
                'volume_paths' => [
                    'ollama_data' => '/root/.ollama',
                ],
                'compose_services' => [],
                'setup_commands' => [],
                'strict_health_check' => true,
                'health_check_timeout_seconds' => 180,
                'is_active' => true,
                'order' => 18,
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, default: string, required: bool, secret: bool}
     */
    private static function envVar(
        string $key,
        string $label,
        string $default = '',
        bool $required = false,
        bool $secret = false,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'default' => $default,
            'required' => $required,
            'secret' => $secret,
        ];
    }
}
