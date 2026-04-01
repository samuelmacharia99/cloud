<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->unique();
            $table->string('registrar')->nullable();
            $table->enum('status', ['active', 'expired', 'suspended'])->default('active');
            $table->date('expires_at');
            $table->boolean('auto_renew')->default(true);
            $table->string('nameserver_1')->nullable();
            $table->string('nameserver_2')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('name');
            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
