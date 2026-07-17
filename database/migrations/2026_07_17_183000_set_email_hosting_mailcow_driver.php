<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('products')
            ->where('type', 'email_hosting')
            ->where(function ($q) {
                $q->whereNull('provisioning_driver_key')
                    ->orWhere('provisioning_driver_key', 'roundcube')
                    ->orWhere('provisioning_driver_key', '');
            })
            ->update(['provisioning_driver_key' => 'mailcow']);

        DB::table('products')
            ->where('slug', 'business-email')
            ->whereNull('resource_limits')
            ->update([
                'resource_limits' => json_encode([
                    'mailboxes' => 10,
                    'aliases' => 20,
                    'quota_mb' => 51200,
                    'mailbox_quota_mb' => 5120,
                ]),
            ]);
    }

    public function down(): void
    {
        DB::table('products')
            ->where('type', 'email_hosting')
            ->where('provisioning_driver_key', 'mailcow')
            ->update(['provisioning_driver_key' => 'roundcube']);
    }
};
