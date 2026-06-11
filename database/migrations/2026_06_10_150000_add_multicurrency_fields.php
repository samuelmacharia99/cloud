<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'preferred_currency')) {
                $table->string('preferred_currency', 3)->nullable()->after('country');
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'currency')) {
                $table->string('currency', 3)->default('KES')->after('total');
                $table->decimal('exchange_rate', 18, 8)->default(1)->after('currency');
                $table->decimal('subtotal_base_kes', 15, 2)->nullable()->after('exchange_rate');
                $table->decimal('tax_base_kes', 15, 2)->nullable()->after('subtotal_base_kes');
                $table->decimal('total_base_kes', 15, 2)->nullable()->after('tax_base_kes');
            }
        });

        if (Schema::hasColumn('invoices', 'currency')) {
            DB::table('invoices')->whereNull('subtotal_base_kes')->update([
                'currency' => 'KES',
                'exchange_rate' => 1,
                'subtotal_base_kes' => DB::raw('subtotal'),
                'tax_base_kes' => DB::raw('tax'),
                'total_base_kes' => DB::raw('total'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            foreach (['total_base_kes', 'tax_base_kes', 'subtotal_base_kes', 'exchange_rate', 'currency'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'preferred_currency')) {
                $table->dropColumn('preferred_currency');
            }
        });
    }
};
