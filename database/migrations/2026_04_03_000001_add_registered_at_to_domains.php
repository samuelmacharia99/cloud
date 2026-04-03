<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->date('registered_at')->nullable()->after('expires_at');
            $table->string('extension')->nullable()->after('name'); // .com, .co.ke, etc
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn('registered_at');
            $table->dropColumn('extension');
        });
    }
};
