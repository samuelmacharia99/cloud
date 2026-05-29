<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_file_audit_logs', function (Blueprint $table) {
            $table->string('action', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('container_file_audit_logs', function (Blueprint $table) {
            $table->enum('action', ['list', 'download', 'upload', 'delete', 'mkdir'])->change();
        });
    }
};
