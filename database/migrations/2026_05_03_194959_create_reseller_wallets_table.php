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
        Schema::create('reseller_wallets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reseller_id')->unique();
            $table->decimal('balance', 12, 2)->default(0.00);
            $table->char('currency', 3)->default('KES');
            $table->enum('status', ['active', 'suspended', 'frozen'])->default('active');
            $table->decimal('low_balance_threshold', 10, 2)->default(5000.00);
            $table->timestamp('last_low_balance_alert_at')->nullable();
            $table->boolean('auto_push_enabled')->default(true);
            $table->timestamps();

            $table->foreign('reseller_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reseller_wallets');
    }
};
