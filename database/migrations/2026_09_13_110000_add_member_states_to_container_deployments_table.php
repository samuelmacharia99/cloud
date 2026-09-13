<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-container state snapshot for a deployment (app, backend, frontend,
 * edge, database, cache...), refreshed by the metrics tick and after stack
 * actions, so the project page can show every member's state without an
 * SSH round-trip. The timestamp is its own column so staleness queries never
 * parse JSON. Names and docker states only; nothing here is a secret.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->json('member_states')->nullable()->after('last_status_check_output');
            $table->timestamp('member_states_checked_at')->nullable()->after('member_states');
        });
    }

    public function down(): void
    {
        Schema::table('container_deployments', function (Blueprint $table) {
            $table->dropColumn(['member_states', 'member_states_checked_at']);
        });
    }
};
