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
        Schema::table('domains', function (Blueprint $table) {
            $table->unsignedBigInteger('reseller_id')->nullable()->after('user_id');
            $table->unsignedBigInteger('domain_order_id')->nullable()->after('reseller_id');
            $table->unsignedBigInteger('pending_transfer_to_user_id')->nullable()->after('domain_order_id');
            $table->string('transfer_token')->unique()->nullable()->after('pending_transfer_to_user_id');
            $table->timestamp('transfer_requested_at')->nullable()->after('transfer_token');

            $table->foreign('reseller_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('domain_order_id')->references('id')->on('reseller_domain_orders')->onDelete('set null');
            $table->foreign('pending_transfer_to_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropForeign(['reseller_id']);
            $table->dropForeign(['domain_order_id']);
            $table->dropForeign(['pending_transfer_to_user_id']);
            $table->dropColumn(['reseller_id', 'domain_order_id', 'pending_transfer_to_user_id', 'transfer_token', 'transfer_requested_at']);
        });
    }
};
