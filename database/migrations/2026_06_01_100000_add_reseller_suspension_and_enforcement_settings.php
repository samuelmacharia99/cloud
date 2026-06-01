<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('reseller_suspended_at')->nullable()->after('package_expires_at');
            $table->string('reseller_suspension_reason')->nullable()->after('reseller_suspended_at');
        });

        $settings = [
            [
                'key' => 'reseller_suspend_on_overdue',
                'value' => 'true',
                'description' => 'Suspend reseller portal access when package subscription is overdue or expired past grace',
            ],
            [
                'key' => 'reseller_cascade_suspend_on_overdue',
                'value' => 'true',
                'description' => 'Suspend managed hosting services on DirectAdmin when reseller account is suspended for billing',
            ],
            [
                'key' => 'reseller_suspend_excess_services',
                'value' => 'true',
                'description' => 'Cron: suspend active services beyond reseller package service slot limit on DirectAdmin',
            ],
            [
                'key' => 'reseller_enforce_limits_on_provision',
                'value' => 'true',
                'description' => 'Block auto-provisioning when reseller is suspended or at package service limit',
            ],
        ];

        foreach ($settings as $setting) {
            if (Setting::where('key', $setting['key'])->doesntExist()) {
                Setting::create($setting);
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['reseller_suspended_at', 'reseller_suspension_reason']);
        });

        Setting::whereIn('key', [
            'reseller_suspend_on_overdue',
            'reseller_cascade_suspend_on_overdue',
            'reseller_suspend_excess_services',
            'reseller_enforce_limits_on_provision',
        ])->delete();
    }
};
