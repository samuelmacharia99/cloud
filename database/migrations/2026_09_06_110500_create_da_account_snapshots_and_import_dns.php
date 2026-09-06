<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('da_account_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('username');
            $table->string('primary_domain')->nullable();
            $table->unsignedInteger('site_count')->default(0);
            $table->unsignedInteger('database_count')->default(0);
            $table->unsignedInteger('mailbox_count')->default(0);
            $table->unsignedInteger('ftp_count')->default(0);
            $table->unsignedInteger('dns_record_count')->default(0);
            $table->boolean('dns_imported')->default(false);
            $table->string('status', 32)->default('captured');
            $table->text('error')->nullable();
            $table->json('payload');
            $table->timestamps();

            $table->index(['service_id', 'created_at']);
        });

        Schema::table('dns_zones', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('domain_id')->constrained('services')->nullOnDelete();
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE dns_records MODIFY COLUMN type VARCHAR(16) NOT NULL');
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE dns_records MODIFY COLUMN type ENUM('A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SOA') NOT NULL");
        }

        Schema::table('dns_zones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
        });

        Schema::dropIfExists('da_account_snapshots');
    }
};
