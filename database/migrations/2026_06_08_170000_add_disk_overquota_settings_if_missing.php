<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            [
                'key' => 'suspend_on_disk_overquota',
                'value' => 'true',
                'description' => 'Suspend DirectAdmin hosting when disk quota is exceeded',
            ],
            [
                'key' => 'disk_overquota_threshold_percent',
                'value' => '100',
                'description' => 'Disk usage percentage of quota before auto-suspension (100 = at limit)',
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
        Setting::whereIn('key', [
            'suspend_on_disk_overquota',
            'disk_overquota_threshold_percent',
        ])->delete();
    }
};
