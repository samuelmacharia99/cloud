<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->enum('status', ['open', 'in_progress', 'on_hold', 'closed'])->default('open');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('resolved_at')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('priority');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
