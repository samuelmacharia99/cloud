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
        Schema::create('direct_admin_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('package_key')->unique();
            $table->decimal('disk_quota', 12, 2)->comment('Disk quota in GB');
            $table->decimal('bandwidth_quota', 12, 2)->nullable()->comment('Bandwidth quota in GB');
            $table->integer('num_domains')->default(1)->comment('Number of domains allowed');
            $table->integer('num_ftp')->default(1)->comment('Number of FTP accounts');
            $table->integer('num_email_accounts')->default(0)->comment('Number of email accounts');
            $table->integer('num_databases')->default(0)->comment('Number of databases');
            $table->integer('num_subdomains')->default(-1)->comment('Number of subdomains (-1 = unlimited)');
            $table->json('features')->nullable()->comment('Additional features as JSON');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('direct_admin_packages');
    }
};
