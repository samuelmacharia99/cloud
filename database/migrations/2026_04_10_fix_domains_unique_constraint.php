<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite doesn't support dropping indexes easily, so we recreate the table
        Schema::table('domains', function (Blueprint $table) {
            // Drop the old unique index
            $table->dropUnique('domains_name_unique');
        });

        // Create a new composite unique index on name + extension
        Schema::table('domains', function (Blueprint $table) {
            $table->unique(['name', 'extension'], 'domains_name_extension_unique');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropUnique('domains_name_extension_unique');
            $table->unique('name', 'domains_name_unique');
        });
    }
};
