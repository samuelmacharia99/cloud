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
        $settings = [
            ['key' => 'recaptcha_enabled', 'value' => 'false'],
            ['key' => 'recaptcha_site_key', 'value' => ''],
            ['key' => 'recaptcha_secret_key', 'value' => ''],
        ];

        foreach ($settings as $setting) {
            \DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        \DB::table('settings')->whereIn('key', [
            'recaptcha_enabled',
            'recaptcha_site_key',
            'recaptcha_secret_key',
        ])->delete();
    }
};
