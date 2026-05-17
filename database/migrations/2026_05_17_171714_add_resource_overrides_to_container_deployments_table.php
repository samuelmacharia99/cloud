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
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->decimal('cpu_limit', 8, 2)->nullable()->after('restart_policy')->comment('CPU limit in cores (e.g. 1.0, 2.5)');
            $table->integer('memory_limit_mb')->nullable()->after('cpu_limit')->comment('Memory limit in MB');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->dropColumn('cpu_limit');
            $table->dropColumn('memory_limit_mb');
        });
    }
};
