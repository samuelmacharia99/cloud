<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        DB::table('cron_jobs')
            ->where('command', 'cron:sync-service-live-status')
            ->update(['command' => 'cron:sync-service-live-status --heal']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        DB::table('cron_jobs')
            ->where('command', 'cron:sync-service-live-status --heal')
            ->update(['command' => 'cron:sync-service-live-status']);
    }
};
