<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_templates', function (Blueprint $table) {
            $table->boolean('strict_health_check')->default(true)->after('versions');
            $table->unsignedInteger('health_check_timeout_seconds')->default(120)->after('strict_health_check');
        });
    }

    public function down(): void
    {
        Schema::table('container_templates', function (Blueprint $table) {
            $table->dropColumn(['strict_health_check', 'health_check_timeout_seconds']);
        });
    }
};
