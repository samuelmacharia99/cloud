<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('database_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->enum('type', ['mysql', 'postgresql', 'mariadb', 'mongodb', 'redis', 'elasticsearch'])->index();
            $table->json('versions')->nullable(); // ["8.0", "8.1", "8.2"]
            $table->string('docker_image')->nullable(); // for containers
            $table->integer('default_port');
            $table->integer('required_ram_mb')->default(256);
            $table->enum('hosting_type', ['directadmin', 'container'])->default('container')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->integer('order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_templates');
    }
};
