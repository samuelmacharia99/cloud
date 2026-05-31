<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('key', 'mpesa_callback_token')->exists()) {
            DB::table('settings')->insert([
                'key' => 'mpesa_callback_token',
                'value' => '',
                'description' => 'Secret token for M-Pesa callback URL (required in production)',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'mpesa_callback_token')->delete();
    }
};
