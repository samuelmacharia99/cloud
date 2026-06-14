<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'payment_purpose')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_purpose', 32)->default('invoice_payment')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'payment_purpose')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->enum('payment_purpose', ['invoice_payment', 'wallet_topup'])->default('invoice_payment')->change();
        });
    }
};
