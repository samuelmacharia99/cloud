<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('ssh_auth_method', 20)->nullable()->after('ssh_username');
            $table->text('ssh_private_key')->nullable()->after('ssh_password');
            $table->text('ssh_key_passphrase')->nullable()->after('ssh_private_key');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn(['ssh_auth_method', 'ssh_private_key', 'ssh_key_passphrase']);
        });
    }
};
