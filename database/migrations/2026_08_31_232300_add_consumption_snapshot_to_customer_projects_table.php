<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_projects', function (Blueprint $table) {
            $table->json('consumption_snapshot')->nullable()->after('resource_pool');
            $table->timestamp('consumption_snapshot_at')->nullable()->after('consumption_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('customer_projects', function (Blueprint $table) {
            $table->dropColumn(['consumption_snapshot', 'consumption_snapshot_at']);
        });
    }
};
