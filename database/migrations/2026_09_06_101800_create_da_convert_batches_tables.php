<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('da_convert_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('admin_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('email_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->boolean('acknowledge_mail_pull')->default(false);
            $table->boolean('acknowledge_addon_sites')->default(false);
            $table->enum('status', [
                'queued',
                'converting',
                'ready_for_cutover',
                'failed',
                'completed',
            ])->default('queued');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['reseller_user_id', 'status']);
        });

        Schema::create('da_convert_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('da_convert_batch_id')->constrained('da_convert_batches')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->enum('status', [
                'queued',
                'converting',
                'converted',
                'failed',
                'blocked',
                'needs_ack',
                'waiting_dns',
                'waiting_mx',
                'done',
            ])->default('queued');
            $table->string('detected_stack')->nullable();
            $table->unsignedInteger('mailbox_count')->default(0);
            $table->boolean('has_addon_sites')->default(false);
            $table->json('blockers')->nullable();
            $table->text('error')->nullable();
            $table->string('hostname')->nullable();
            $table->string('target_ip')->nullable();
            $table->boolean('dns_managed')->default(false);
            $table->boolean('dns_ok')->default(false);
            $table->boolean('ssl_ok')->default(false);
            $table->json('cutover_notes')->nullable();
            $table->timestamps();

            $table->index(['da_convert_batch_id', 'status']);
            $table->index('service_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('da_convert_batch_items');
        Schema::dropIfExists('da_convert_batches');
    }
};
