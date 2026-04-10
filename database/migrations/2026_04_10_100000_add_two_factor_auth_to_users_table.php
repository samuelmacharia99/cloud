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
            $table->boolean('two_factor_enabled')->default(false)->after('notification_phones');
            $table->string('two_factor_code')->nullable()->after('two_factor_enabled');
            $table->timestamp('two_factor_code_expires_at')->nullable()->after('two_factor_code');
            $table->json('two_factor_recovery_codes')->nullable()->after('two_factor_code_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'two_factor_enabled',
                'two_factor_code',
                'two_factor_code_expires_at',
                'two_factor_recovery_codes',
            ]);
        });
    }
};
