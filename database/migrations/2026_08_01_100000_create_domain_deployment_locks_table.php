<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_deployment_locks', function (Blueprint $table) {
            $table->id();
            $table->string('fqdn', 253)->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('status', 20)->default('locked'); // locked|cooldown
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('cool_down_until')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('cool_down_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_deployment_locks');
    }
};
