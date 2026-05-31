<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('settings')->where('key', 'reseller_package_invoice_advance_days')->exists()) {
            DB::table('settings')->insert([
                'key' => 'reseller_package_invoice_advance_days',
                'value' => '10',
                'description' => 'Generate reseller package renewal invoices this many days before package expiry',
            ]);
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'reseller_package_invoice_advance_days')->delete();
    }
};
