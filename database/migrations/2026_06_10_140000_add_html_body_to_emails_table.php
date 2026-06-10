<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            if (! Schema::hasColumn('emails', 'html_body')) {
                $table->longText('html_body')->nullable()->after('body');
            }
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            if (Schema::hasColumn('emails', 'html_body')) {
                $table->dropColumn('html_body');
            }
        });
    }
};
