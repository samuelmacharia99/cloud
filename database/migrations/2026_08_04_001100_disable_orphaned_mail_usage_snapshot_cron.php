<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disable cron jobs left behind after the usage-billing experiment was reverted.
     * Artisan command cron:collect-mail-usage-snapshots no longer exists.
     */
    public function up(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        DB::table('cron_jobs')
            ->whereIn('command', [
                'cron:collect-mail-usage-snapshots',
            ])
            ->update([
                'enabled' => false,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Command is not in the codebase anymore — do not re-enable.
    }
};
