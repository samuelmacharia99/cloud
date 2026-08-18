<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('domains') || Schema::hasColumn('domains', 'registrar_handle')) {
            return;
        }

        Schema::table('domains', function (Blueprint $table) {
            $table->string('registrar_handle')->nullable()->after('registrar_external_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('domains') || ! Schema::hasColumn('domains', 'registrar_handle')) {
            return;
        }

        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('registrar_handle');
        });
    }
};
