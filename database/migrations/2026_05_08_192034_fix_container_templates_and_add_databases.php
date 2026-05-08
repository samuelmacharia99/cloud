<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\ContainerTemplate;
use App\Models\DatabaseTemplate;

return new class extends Migration
{
    public function up(): void
    {
        // Fix WordPress to DirectAdmin routing
        ContainerTemplate::where('slug', 'wordpress')->update(['hosting_type' => 'directadmin']);

        // Add Laravel as DirectAdmin template
        ContainerTemplate::firstOrCreate(['slug' => 'laravel'], [
            'name' => 'Laravel Application',
            'description' => 'PHP Laravel framework — full MVC, Eloquent ORM, Artisan CLI',
            'category' => 'web',
            'hosting_type' => 'directadmin',
            'docker_image' => 'php:8.2-fpm-alpine',
            'default_port' => 80,
            'required_ram_mb' => 512,
            'required_cpu_cores' => 0.5,
            'required_storage_gb' => 5,
            'is_active' => true,
            'order' => 11,
        ]);

        // Add MySQL 8.0 as a container database (for use with Node.js, Python, Go, etc.)
        DatabaseTemplate::firstOrCreate(['slug' => 'mysql-container'], [
            'name' => 'MySQL 8.0',
            'type' => 'mysql',
            'description' => 'MySQL 8.0 — containerised relational database for container-hosted applications',
            'versions' => json_encode(['8.0', '5.7']),
            'docker_image' => 'mysql:8.0',
            'default_port' => 3306,
            'required_ram_mb' => 512,
            'hosting_type' => 'container',
            'is_active' => true,
            'order' => 5,
        ]);
    }

    public function down(): void
    {
        ContainerTemplate::where('slug', 'wordpress')->update(['hosting_type' => 'container']);
        ContainerTemplate::where('slug', 'laravel')->delete();
        DatabaseTemplate::where('slug', 'mysql-container')->delete();
    }
};
