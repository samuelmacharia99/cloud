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
            $table->string('selected_version')->nullable()->after('restart_policy')->comment('Selected version for templated containers (e.g. 20-alpine for Node.js)');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->dropColumn('selected_version');
        });
    }
};
