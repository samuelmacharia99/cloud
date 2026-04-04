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
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('reseller_package_id')
                  ->nullable()
                  ->constrained('reseller_packages')
                  ->nullOnDelete()
                  ->after('is_reseller');
            $table->timestamp('package_subscribed_at')->nullable()->after('reseller_package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['reseller_package_id']);
            $table->dropColumn(['reseller_package_id', 'package_subscribed_at']);
        });
    }
};
