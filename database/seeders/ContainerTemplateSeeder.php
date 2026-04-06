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
                'description' => 'Full WordPress CMS with dedicated MySQL 8.0 database. Includes auto-generated admin credentials.',
                'category' => 'web',
                'docker_image' => 'wordpress:latest',
                'default_port' => 80,
                'required_ram_mb' => 512,
                'required_cpu_cores' => 1.0,
                'required_storage_gb' => 5,
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
                    'wp_content' => '/var/www/html/wp-content',
                    'wp_data' => '/var/www/html',
                ],
                'compose_services' => [
                    'mysql' => [
                        'image' => 'mysql:8.0',
                        'container_name' => 'mysql-wordpress',
                        'restart' => 'unless-stopped',
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
                            'test' => ['CMD', 'mysqladmin', 'ping', '-h', 'localhost'],
                            'interval' => '10s',
                            'timeout' => '5s',
                            'retries' => 5,
                        ],
                    ],
                ],
                'setup_commands' => [],
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
                'is_active' => true,
                'order' => 2,
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
                'is_active' => true,
                'order' => 3,
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
                        'default' => 'true',
                        'required' => false,
                        'secret' => false,
                    ],
                ],
                'volume_paths' => [
                    'app_data' => '/app',
                ],
                'compose_services' => [],
                'setup_commands' => [
                    'npm install',
                    'npm start',
                ],
                'is_active' => true,
                'order' => 4,
            ]
        );

        // 5. Static Website
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
                'is_active' => true,
                'order' => 5,
            ]
        );
    }
}
