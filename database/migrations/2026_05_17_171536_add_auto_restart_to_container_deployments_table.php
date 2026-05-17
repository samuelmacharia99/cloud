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
            $table->boolean('auto_restart')->default(true)->after('status');
            $table->enum('restart_policy', ['always', 'on-failure', 'unless-stopped', 'no'])->default('unless-stopped')->after('auto_restart');
            $table->integer('restart_attempts')->default(0)->after('restart_policy');
            $table->timestamp('last_restart_at')->nullable()->after('restart_attempts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->dropColumn('auto_restart');
            $table->dropColumn('restart_policy');
            $table->dropColumn('restart_attempts');
            $table->dropColumn('last_restart_at');
        });
    }
};
