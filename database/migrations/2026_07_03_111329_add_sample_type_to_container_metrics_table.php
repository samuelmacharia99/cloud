<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('container_metrics', 'sample_type')) {
            Schema::table('container_metrics', function (Blueprint $table) {
                $table->string('sample_type', 20)->default('usage')->after('container_deployment_id');
            });
        }

        Schema::table('container_metrics', function (Blueprint $table) {
            $table->index(
                ['container_deployment_id', 'sample_type', 'recorded_at'],
                'cm_deploy_sample_recorded_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('container_metrics', function (Blueprint $table) {
            $table->dropIndex('cm_deploy_sample_recorded_idx');
            $table->dropColumn('sample_type');
        });
    }
};
