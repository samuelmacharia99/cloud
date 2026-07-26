<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('bundled_email_product_id')
                ->nullable()
                ->after('container_template_id')
                ->constrained('products')
                ->nullOnDelete();
            $table->boolean('bundle_email_include_in_invoice')
                ->default(false)
                ->after('bundled_email_product_id');
            $table->string('bundle_email_billing_cycle', 32)
                ->nullable()
                ->after('bundle_email_include_in_invoice');
            $table->unsignedTinyInteger('bundle_email_billing_delay_months')
                ->default(0)
                ->after('bundle_email_billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bundled_email_product_id');
            $table->dropColumn([
                'bundle_email_include_in_invoice',
                'bundle_email_billing_cycle',
                'bundle_email_billing_delay_months',
            ]);
        });
    }
};
