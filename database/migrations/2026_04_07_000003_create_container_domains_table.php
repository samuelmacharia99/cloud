<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('container_deployment_id');
            $table->string('domain')->unique();
            $table->enum('status', ['pending', 'active', 'failed', 'removing'])->default('pending');
            $table->boolean('ssl_enabled')->default(false);
            $table->string('ssl_certificate_path')->nullable();
            $table->string('ssl_key_path')->nullable();
            $table->string('nginx_config_path')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('container_deployment_id')
                ->references('id')
                ->on('container_deployments')
                ->onDelete('cascade');

            $table->index('container_deployment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_domains');
    }
};
