<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'amount_base_kes')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->decimal('amount_base_kes', 15, 2)->nullable()->after('currency');
            });
        }

        DB::table('payments')
            ->whereNull('amount_base_kes')
            ->orderBy('id')
            ->chunkById(500, function ($payments): void {
                $rates = DB::table('currencies')
                    ->whereIn('code', $payments->pluck('currency')->filter()->unique())
                    ->pluck('exchange_rate', 'code');

                foreach ($payments as $payment) {
                    $currency = strtoupper((string) ($payment->currency ?: 'KES'));
                    $rate = $currency === 'KES' ? 1.0 : (float) ($rates[$currency] ?? 0);

                    if ($rate <= 0) {
                        continue;
                    }

                    DB::table('payments')->where('id', $payment->id)->update([
                        'amount_base_kes' => round((float) $payment->amount / $rate, 2),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'amount_base_kes')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('amount_base_kes');
            });
        }
    }
};
