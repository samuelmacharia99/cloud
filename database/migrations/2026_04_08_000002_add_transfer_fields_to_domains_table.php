<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            // Transfer-specific fields
            $table->enum('type', ['registration', 'transfer'])->default('registration')->after('extension');
            $table->enum('transfer_status', ['pending', 'initiated', 'in_progress', 'completed', 'failed', 'cancelled'])->nullable()->after('status');
            $table->string('epp_code')->nullable()->after('transfer_status');
            $table->string('old_registrar')->nullable()->after('epp_code');
            $table->string('old_registrar_url')->nullable()->after('old_registrar');
            $table->timestamp('transfer_initiated_at')->nullable()->after('old_registrar_url');
            $table->timestamp('transfer_completed_at')->nullable()->after('transfer_initiated_at');
            $table->text('transfer_notes')->nullable()->after('transfer_completed_at');
            $table->integer('transfer_authorization_code')->nullable()->after('transfer_notes');

            // Index for faster queries
            $table->index('type');
            $table->index('transfer_status');
        });
    }

    public function down(): void
    {
        Schema::table('domains', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['transfer_status']);
            $table->dropColumn([
                'type',
                'transfer_status',
                'epp_code',
                'old_registrar',
                'old_registrar_url',
                'transfer_initiated_at',
                'transfer_completed_at',
                'transfer_notes',
                'transfer_authorization_code',
            ]);
        });
    }
};
