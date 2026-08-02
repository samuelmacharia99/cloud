<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('container_terminal_logs', function (Blueprint $table) {
            // Signed tinyint max is 127; OOM aborts use 134+ and broke log inserts.
            $table->smallInteger('exit_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('container_terminal_logs', function (Blueprint $table) {
            $table->tinyInteger('exit_code')->nullable()->change();
        });
    }
};
