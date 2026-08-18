<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->json('registrant_contact')->nullable()->after('notes');
            $table->boolean('whois_privacy')->default(false)->after('registrant_contact');
            $table->boolean('registry_locked')->default(false)->after('whois_privacy');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropColumn(['registrant_contact', 'whois_privacy', 'registry_locked']);
        });
    }
};
