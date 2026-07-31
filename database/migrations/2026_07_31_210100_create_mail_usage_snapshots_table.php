<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_usage_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedInteger('mailbox_count')->default(0);
            $table->unsignedInteger('alias_count')->default(0);
            $table->unsignedBigInteger('quota_used_mb')->nullable();
            $table->unsignedBigInteger('quota_limit_mb')->nullable();
            $table->timestamp('sampled_at');
            $table->timestamps();

            $table->index(['service_id', 'sampled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_usage_snapshots');
    }
};
