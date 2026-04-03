<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->foreignId('node_id')->nullable()->after('reseller_id')->constrained('nodes')->nullOnDelete();
            $table->index('node_id');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropForeignIdFor('nodes');
            $table->dropIndex(['node_id']);
        });
    }
};
