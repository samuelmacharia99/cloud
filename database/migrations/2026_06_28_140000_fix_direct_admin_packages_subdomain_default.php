<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_admin_packages', function (Blueprint $table) {
            $table->integer('num_subdomains')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('direct_admin_packages', function (Blueprint $table) {
            $table->integer('num_subdomains')->default(-1)->change();
        });
    }
};
