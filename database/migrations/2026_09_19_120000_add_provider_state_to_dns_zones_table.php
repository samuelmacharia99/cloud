<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record what the DNS provider says about a zone, rather than assuming it.
 *
 * The existing status column is an enum of active/inactive and describes the
 * platform's own intent. Cloudflare has its own vocabulary — a zone is
 * initializing, then pending until it sees the nameservers at the registry,
 * then active, and may later be moved or deleted — and the platform was
 * writing "active" for every zone the moment it created one, so a domain that
 * had never gone live, or had since been removed, read exactly like one that
 * was serving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->string('provider_status')->nullable()->after('external_zone_id');
            $table->timestamp('provider_checked_at')->nullable()->after('provider_status');
            $table->timestamp('activated_at')->nullable()->after('provider_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->dropColumn(['provider_status', 'provider_checked_at', 'activated_at']);
        });
    }
};
