<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'payment_purpose')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->string('payment_purpose', 32)
                    ->default('invoice_payment')
                    ->after('payment_method');
            });

            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_purpose', 32)
                ->default('invoice_payment')
                ->change();
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive: narrowing this column would reject
        // existing credit_topup records and is unsafe in production.
    }
};
