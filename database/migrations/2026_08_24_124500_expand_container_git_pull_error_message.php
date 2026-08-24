<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_git_pulls', function (Blueprint $table) {
            $table->longText('error_message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('container_git_pulls', function (Blueprint $table) {
            $table->text('error_message')->nullable()->change();
        });
    }
};
