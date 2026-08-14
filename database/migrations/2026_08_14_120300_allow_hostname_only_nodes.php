<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('ip_address')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Keep hostname-only nodes valid on rollback.
    }
};
