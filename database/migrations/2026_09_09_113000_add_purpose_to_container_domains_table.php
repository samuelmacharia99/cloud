<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_domains', function (Blueprint $table) {
            $table->string('purpose', 32)->default('web')->after('domain');
            $table->index(['container_deployment_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('container_domains', function (Blueprint $table) {
            $table->dropIndex(['container_deployment_id', 'purpose']);
            $table->dropColumn('purpose');
        });
    }
};
