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
            if (!Schema::hasColumn('users', 'package_expires_at')) {
                $table->date('package_expires_at')->nullable()->after('package_subscribed_at');
            }
        });

        // Backfill: resellers with a subscription get package_expires_at = subscription date + 1 month
        $users = \DB::table('users')
            ->where('is_reseller', true)
            ->whereNotNull('package_subscribed_at')
            ->whereNull('package_expires_at')
            ->get();

        foreach ($users as $user) {
            \DB::table('users')
                ->where('id', $user->id)
                ->update([
                    'package_expires_at' => \Carbon\Carbon::parse($user->package_subscribed_at)->addMonth()->toDateString()
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'package_expires_at')) {
                $table->dropColumn('package_expires_at');
            }
        });
    }
};
