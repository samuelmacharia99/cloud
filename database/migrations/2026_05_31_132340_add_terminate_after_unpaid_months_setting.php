<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('settings')->where('key', 'terminate_after_unpaid_months')->exists();

        if (! $exists) {
            DB::table('settings')->insert([
                'key' => 'terminate_after_unpaid_months',
                'value' => '4',
                'description' => 'Months an invoice can remain unpaid before service termination',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'terminate_after_unpaid_months')->delete();
    }
};
