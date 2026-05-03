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
            if (!Schema::hasColumn('domains', 'reseller_id')) {
                $table->unsignedBigInteger('reseller_id')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('domains', 'domain_order_id')) {
                $table->unsignedBigInteger('domain_order_id')->nullable()->after('reseller_id');
            }
            if (!Schema::hasColumn('domains', 'pending_transfer_to_user_id')) {
                $table->unsignedBigInteger('pending_transfer_to_user_id')->nullable()->after('domain_order_id');
            }
            if (!Schema::hasColumn('domains', 'transfer_token')) {
                $table->string('transfer_token')->unique()->nullable()->after('pending_transfer_to_user_id');
            }
            if (!Schema::hasColumn('domains', 'transfer_requested_at')) {
                $table->timestamp('transfer_requested_at')->nullable()->after('transfer_token');
            }
        });

        Schema::table('domains', function (Blueprint $table) {
            // Add foreign keys if they don't exist
            try {
                if (Schema::hasColumn('domains', 'reseller_id')) {
                    $table->foreign('reseller_id')->references('id')->on('users')->onDelete('set null');
                }
            } catch (\Exception $e) {
                // Foreign key might already exist
            }

            try {
                if (Schema::hasColumn('domains', 'domain_order_id')) {
                    $table->foreign('domain_order_id')->references('id')->on('reseller_domain_orders')->onDelete('set null');
                }
            } catch (\Exception $e) {
                // Foreign key might already exist
            }

            try {
                if (Schema::hasColumn('domains', 'pending_transfer_to_user_id')) {
                    $table->foreign('pending_transfer_to_user_id')->references('id')->on('users')->onDelete('set null');
                }
            } catch (\Exception $e) {
                // Foreign key might already exist
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Try to drop foreign keys, but don't fail if they don't exist
            try {
                $table->dropForeign('domains_reseller_id_foreign');
            } catch (\Exception $e) {
                // Foreign key might not exist
            }

            try {
                $table->dropForeign('domains_domain_order_id_foreign');
            } catch (\Exception $e) {
                // Foreign key might not exist
            }

            try {
                $table->dropForeign('domains_pending_transfer_to_user_id_foreign');
            } catch (\Exception $e) {
                // Foreign key might not exist
            }

            // Drop columns if they exist
            if (Schema::hasColumn('domains', 'reseller_id')) {
                $table->dropColumn('reseller_id');
            }
            if (Schema::hasColumn('domains', 'domain_order_id')) {
                $table->dropColumn('domain_order_id');
            }
            if (Schema::hasColumn('domains', 'pending_transfer_to_user_id')) {
                $table->dropColumn('pending_transfer_to_user_id');
            }
            if (Schema::hasColumn('domains', 'transfer_token')) {
                $table->dropColumn('transfer_token');
            }
            if (Schema::hasColumn('domains', 'transfer_requested_at')) {
                $table->dropColumn('transfer_requested_at');
            }
        });
    }
};
