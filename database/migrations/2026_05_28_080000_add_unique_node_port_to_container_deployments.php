<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->unique(['node_id', 'assigned_port'], 'container_deployments_node_port_unique');
        });
    }

    public function down(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->dropUnique('container_deployments_node_port_unique');
        });
    }
};
