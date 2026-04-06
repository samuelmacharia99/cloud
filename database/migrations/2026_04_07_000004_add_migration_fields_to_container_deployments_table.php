<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->unsignedBigInteger('migrated_from_node_id')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->string('migration_reason')->nullable();

            $table->foreign('migrated_from_node_id')
                ->references('id')
                ->on('nodes')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->dropForeignIdFor('nodes', 'migrated_from_node_id');
            $table->dropColumn('migrated_from_node_id');
            $table->dropColumn('migrated_at');
            $table->dropColumn('migration_reason');
        });
    }
};
