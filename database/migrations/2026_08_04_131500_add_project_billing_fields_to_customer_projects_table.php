<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_projects', function (Blueprint $table) {
            $table->foreignId('billing_service_id')
                ->nullable()
                ->after('name')
                ->constrained('services')
                ->nullOnDelete();
            $table->string('recipe_key', 64)->nullable()->after('billing_service_id');
            $table->json('resource_pool')->nullable()->after('recipe_key');
        });
    }

    public function down(): void
    {
        Schema::table('customer_projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_service_id');
            $table->dropColumn(['recipe_key', 'resource_pool']);
        });
    }
};
