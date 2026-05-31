<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('name');
            $table->string('subject');
            $table->longText('body');
            $table->enum('recipient_type', ['customer', 'admin', 'reseller', 'both'])->default('customer');
            $table->string('description')->nullable();
            $table->json('available_variables')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('event_key');
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key');
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'event_key']);
            $table->index('event_key');
        });

        Schema::table('emails', function (Blueprint $table) {
            if (! Schema::hasColumn('emails', 'event_key')) {
                $table->string('event_key')->nullable()->after('subject');
            }
            if (! Schema::hasColumn('emails', 'message_id')) {
                $table->string('message_id')->nullable()->after('event_key');
            }
            if (! Schema::hasColumn('emails', 'user_id')) {
                $table->foreignId('user_id')->nullable()->after('recipient')->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('emails', function (Blueprint $table) {
            if (Schema::hasColumn('emails', 'user_id')) {
                $table->dropForeign(['user_id']);
                $table->dropColumn('user_id');
            }
            if (Schema::hasColumn('emails', 'message_id')) {
                $table->dropColumn('message_id');
            }
            if (Schema::hasColumn('emails', 'event_key')) {
                $table->dropColumn('event_key');
            }
        });

        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('email_templates');
    }
};
