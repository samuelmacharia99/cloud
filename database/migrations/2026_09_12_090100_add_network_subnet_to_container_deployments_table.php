<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            // The private network's subnet, allocated per node by the platform
            // so Docker's address pools never run dry. Null on stacks that
            // still sit on the shared bridge.
            $table->string('network_subnet', 32)->nullable()->after('assigned_port');
            $table->unique(['node_id', 'network_subnet'], 'container_deployments_node_subnet_unique');
        });
    }

    public function down(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->dropUnique('container_deployments_node_subnet_unique');
            $table->dropColumn('network_subnet');
        });
    }
};
