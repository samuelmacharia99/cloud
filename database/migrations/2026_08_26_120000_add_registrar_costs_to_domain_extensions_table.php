<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_extensions', function (Blueprint $table) {
            $table->decimal('registrar_register_cost_usd', 10, 2)->nullable()->after('transfer_price');
            $table->decimal('registrar_renew_cost_usd', 10, 2)->nullable()->after('registrar_register_cost_usd');
            $table->decimal('registrar_transfer_cost_usd', 10, 2)->nullable()->after('registrar_renew_cost_usd');
            $table->decimal('registrar_register_cost_kes', 10, 2)->nullable()->after('registrar_transfer_cost_usd');
            $table->decimal('registrar_renew_cost_kes', 10, 2)->nullable()->after('registrar_register_cost_kes');
            $table->decimal('registrar_transfer_cost_kes', 10, 2)->nullable()->after('registrar_renew_cost_kes');
            $table->timestamp('registrar_cost_synced_at')->nullable()->after('registrar_transfer_cost_kes');
        });
    }

    public function down(): void
    {
        Schema::table('domain_extensions', function (Blueprint $table) {
            $table->dropColumn([
                'registrar_register_cost_usd',
                'registrar_renew_cost_usd',
                'registrar_transfer_cost_usd',
                'registrar_register_cost_kes',
                'registrar_renew_cost_kes',
                'registrar_transfer_cost_kes',
                'registrar_cost_synced_at',
            ]);
        });
    }
};
