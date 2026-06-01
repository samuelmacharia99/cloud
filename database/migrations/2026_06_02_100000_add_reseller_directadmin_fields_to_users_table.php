<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('directadmin_username', 48)->nullable()->after('reseller_suspension_reason');
            $table->foreignId('reseller_node_id')->nullable()->after('directadmin_username')->constrained('nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reseller_node_id');
            $table->dropColumn('directadmin_username');
        });
    }
};
