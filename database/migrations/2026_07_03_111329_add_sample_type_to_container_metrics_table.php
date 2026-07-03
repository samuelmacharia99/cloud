<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_metrics', function (Blueprint $table) {
            $table->string('sample_type', 20)->default('usage')->after('container_deployment_id');
            $table->index(['container_deployment_id', 'sample_type', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::table('container_metrics', function (Blueprint $table) {
            $table->dropIndex(['container_deployment_id', 'sample_type', 'recorded_at']);
            $table->dropColumn('sample_type');
        });
    }
};
