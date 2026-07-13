<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_backups', function (Blueprint $table) {
            $table->string('storage_driver', 32)->default('node')->after('backup_path');
        });
    }

    public function down(): void
    {
        Schema::table('container_backups', function (Blueprint $table) {
            $table->dropColumn('storage_driver');
        });
    }
};
