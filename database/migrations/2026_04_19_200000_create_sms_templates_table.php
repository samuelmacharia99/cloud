<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('name');
            $table->text('body');
            $table->enum('recipient_type', ['customer', 'admin', 'both'])->default('customer');
            $table->string('description')->nullable();
            $table->json('available_variables')->nullable();
            $table->timestamps();

            $table->index('event_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
