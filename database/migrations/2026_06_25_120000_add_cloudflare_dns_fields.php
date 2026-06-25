<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->boolean('cloudflare_dns_enabled')->default(false)->after('nameserver_4');
            $table->string('cloudflare_zone_id')->nullable()->after('cloudflare_dns_enabled');
        });

        Schema::table('dns_zones', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('name');
            $table->string('external_zone_id')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['cloudflare_dns_enabled', 'cloudflare_zone_id']);
        });

        Schema::table('dns_zones', function (Blueprint $table) {
            $table->dropColumn(['provider', 'external_zone_id']);
        });
    }
};
