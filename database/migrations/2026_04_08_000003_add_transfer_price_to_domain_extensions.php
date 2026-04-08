<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domain_extensions', function (Blueprint $table) {
            $table->decimal('transfer_price', 10, 2)->default(0)->after('auto_renewal');
        });
    }

    public function down(): void
    {
        Schema::table('domain_extensions', function (Blueprint $table) {
            $table->dropColumn('transfer_price');
        });
    }
};
