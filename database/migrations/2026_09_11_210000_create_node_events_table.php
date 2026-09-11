<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A node that failed at three in the morning still matters at nine.
     *
     * The platform kept a rolling gauge of a server's memory and disk and no
     * record of anything that happened to it, so "the site was slow last night"
     * had no answer. Written on transitions only: a service going down, one
     * coming back, a threshold crossed, a repair applied and what it did.
     */
    public function up(): void
    {
        Schema::create('node_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('node_id')->constrained('nodes')->cascadeOnDelete();
            $table->string('event', 100);
            $table->string('severity', 20)->default('info');
            $table->json('payload')->nullable();
            $table->timestamp('recorded_at')->useCurrent();

            $table->index(['node_id', 'recorded_at'], 'ne_node_recorded_idx');
            $table->index('event', 'ne_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_events');
    }
};
