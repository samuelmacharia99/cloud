<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'reseller_directadmin_sync_failed_at')) {
                $table->timestamp('reseller_directadmin_sync_failed_at')
                    ->nullable()
                    ->after('reseller_suspension_reason');
            }
        });

        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        $updated = DB::table('cron_jobs')
            ->where('command', 'cron:suspend-resellers')
            ->update([
                'name' => 'Suspend Resellers',
                'description' => 'Suspends resellers with overdue or expired package subscriptions; optionally cascades to DirectAdmin.',
                'schedule' => '*/15 * * * *',
                'enabled' => true,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            DB::table('cron_jobs')->insert([
                'name' => 'Suspend Resellers',
                'description' => 'Suspends resellers with overdue or expired package subscriptions; optionally cascades to DirectAdmin.',
                'command' => 'cron:suspend-resellers',
                'schedule' => '*/15 * * * *',
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'reseller_directadmin_sync_failed_at')) {
                $table->dropColumn('reseller_directadmin_sync_failed_at');
            }
        });

        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        DB::table('cron_jobs')
            ->where('command', 'cron:suspend-resellers')
            ->update([
                'schedule' => '30 4 * * *',
                'updated_at' => now(),
            ]);
    }
};
