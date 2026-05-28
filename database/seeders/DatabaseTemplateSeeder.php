<?php

namespace Database\Seeders;

use App\Models\DatabaseTemplate;
use Illuminate\Database\Seeder;

class DatabaseTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // DirectAdmin databases (for PHP/WordPress/Laravel via DirectAdmin shared hosting)
        DatabaseTemplate::firstOrCreate(
            ['slug' => 'mysql-directadmin'],
            [
                'name' => 'MySQL',
                'type' => 'mysql',
                'description' => 'MySQL — world\'s most popular open source relational database',
                'versions' => json_encode(['8.0', '5.7']),
                'docker_image' => 'mysql:8.0',
                'default_port' => 3306,
                'required_ram_mb' => 256,
                'hosting_type' => 'directadmin',
                'is_active' => true,
                'order' => 1,
            ]
        );

        DatabaseTemplate::firstOrCreate(
            ['slug' => 'mariadb-directadmin'],
            [
                'name' => 'MariaDB',
                'type' => 'mariadb',
                'description' => 'MariaDB — MySQL-compatible open source relational database',
                'versions' => json_encode(['10.6', '10.5']),
                'docker_image' => 'mariadb:10.6',
                'default_port' => 3306,
                'required_ram_mb' => 256,
                'hosting_type' => 'directadmin',
                'is_active' => true,
                'order' => 2,
            ]
        );

        // Container databases
        DatabaseTemplate::firstOrCreate(
            ['slug' => 'postgresql-container'],
            [
                'name' => 'PostgreSQL',
                'type' => 'postgresql',
                'description' => 'PostgreSQL — advanced open source relational database',
                'versions' => json_encode(['16', '15', '14']),
                'docker_image' => 'postgres:16-alpine',
                'default_port' => 5432,
                'required_ram_mb' => 256,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 3,
            ]
        );

        DatabaseTemplate::firstOrCreate(
            ['slug' => 'mongodb-container'],
            [
                'name' => 'MongoDB',
                'type' => 'mongodb',
                'description' => 'MongoDB — NoSQL document database with flexible schema',
                'versions' => json_encode(['7.0', '6.0']),
                'docker_image' => 'mongo:7.0',
                'default_port' => 27017,
                'required_ram_mb' => 512,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 4,
            ]
        );

        DatabaseTemplate::firstOrCreate(
            ['slug' => 'redis-container'],
            [
                'name' => 'Redis',
                'type' => 'redis',
                'description' => 'Redis — in-memory data structure store for caching and sessions',
                'versions' => json_encode(['7.0', '6.2']),
                'docker_image' => 'redis:7-alpine',
                'default_port' => 6379,
                'required_ram_mb' => 256,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 5,
            ]
        );

        DatabaseTemplate::firstOrCreate(
            ['slug' => 'mysql-container'],
            [
                'name' => 'MySQL 8.0',
                'type' => 'mysql',
                'description' => 'MySQL 8.0 — containerised relational database for container-hosted applications',
                'versions' => json_encode(['8.0', '5.7']),
                'docker_image' => 'mysql:8.0',
                'default_port' => 3306,
                'required_ram_mb' => 512,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 6,
            ]
        );

        DatabaseTemplate::firstOrCreate(
            ['slug' => 'mariadb-container'],
            [
                'name' => 'MariaDB',
                'type' => 'mariadb',
                'description' => 'MariaDB container for app workloads that need MySQL compatibility',
                'versions' => json_encode(['11.4', '11.3', '10.11']),
                'docker_image' => 'mariadb:11.4',
                'default_port' => 3306,
                'required_ram_mb' => 384,
                'hosting_type' => 'container',
                'is_active' => true,
                'order' => 7,
            ]
        );
    }
}
