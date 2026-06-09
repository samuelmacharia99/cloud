<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'live_status')) {
                $table->string('live_status', 32)->nullable()->after('status');
            }
            if (! Schema::hasColumn('services', 'live_status_label')) {
                $table->string('live_status_label')->nullable()->after('live_status');
            }
            if (! Schema::hasColumn('services', 'live_status_source')) {
                $table->string('live_status_source', 32)->nullable()->after('live_status_label');
            }
            if (! Schema::hasColumn('services', 'live_status_checked_at')) {
                $table->timestamp('live_status_checked_at')->nullable()->after('live_status_source');
            }
            if (! Schema::hasColumn('services', 'live_status_detail')) {
                $table->json('live_status_detail')->nullable()->after('live_status_checked_at');
            }
            if (! Schema::hasColumn('services', 'live_status_mismatch')) {
                $table->boolean('live_status_mismatch')->default(false)->after('live_status_detail');
            }
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'live_status',
                'live_status_label',
                'live_status_source',
                'live_status_checked_at',
                'live_status_detail',
                'live_status_mismatch',
            ]);
        });
    }
};
