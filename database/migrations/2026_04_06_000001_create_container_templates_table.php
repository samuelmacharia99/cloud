<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_templates', function (Blueprint $table) {
            $table->id();

            // Identity & metadata
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->default('web'); // web, database, utility, cache

            // Docker image & networking
            $table->string('docker_image'); // wordpress:latest, ghost:5-alpine, etc.
            $table->unsignedSmallInteger('default_port')->default(80);

            // Resource requirements
            $table->unsignedInteger('required_ram_mb')->default(512);
            $table->decimal('required_cpu_cores', 3, 1)->default(0.5); // 0.5, 1, 2, 4
            $table->unsignedInteger('required_storage_gb')->default(2);

            // Configuration templates
            $table->json('environment_variables')->nullable(); // [{key, label, default, required, secret}, ...]
            $table->json('volume_paths')->nullable(); // {data: /var/www/html, mysql: /var/lib/mysql, ...}
            $table->json('compose_services')->nullable(); // extra services like mysql:8.0, redis:latest sidecar definitions
            $table->json('setup_commands')->nullable(); // ["wp core install --url=... --admin_user=...", ...]

            // Lifecycle
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('order')->default(0); // for sorting in UI

            $table->timestamps();

            // Indexes
            $table->index('is_active');
            $table->index('category');
            $table->index('order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_templates');
    }
};
