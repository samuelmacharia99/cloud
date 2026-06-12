<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('reseller_domain_orders', 'order_type')) {
                $table->string('order_type', 20)->default('registration')->after('extension');
                $table->index('order_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('reseller_domain_orders', function (Blueprint $table) {
            if (Schema::hasColumn('reseller_domain_orders', 'order_type')) {
                $table->dropIndex(['order_type']);
                $table->dropColumn('order_type');
            }
        });
    }
};
